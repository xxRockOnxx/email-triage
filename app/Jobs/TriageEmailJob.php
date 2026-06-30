<?php

namespace App\Jobs;

use App\Contracts\EmbeddingBackendContract;
use App\Contracts\TriageBackendContract;
use App\Contracts\VectorStoreContract;
use App\DTOs\TriageRequest;
use App\Models\Email;
use App\Models\PipelineLog;
use App\Models\TriageResult;
use App\Services\Category\CategoryResolver;
use App\Services\Reputation\SenderReputationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline stage 3: builds RAG context + sender reputation, calls the
 * configured LLM triage backend (anonymized text only), resolves the
 * category outcome, applies confidence-based routing, and persists the
 * TriageResult. Dispatches EmbedAndStoreJob last so this email becomes
 * available as a future RAG example.
 */
#[Timeout(310)]
class TriageEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 20;

    public function __construct(private readonly int $emailId) {}

    public function handle(
        TriageBackendContract $triageBackend,
        EmbeddingBackendContract $embeddingBackend,
        VectorStoreContract $vectorStore,
        CategoryResolver $categoryResolver,
        SenderReputationService $reputationService,
    ): void {
        $email = Email::findOrFail($this->emailId);

        if (! $email->is_anonymized) {
            PipelineLog::record($email->id, 'triage', 'skipped', $this->attempts(), 'email not yet anonymized');
            Log::warning('TriageEmailJob skipped — email not yet anonymized', ['email_id' => $email->id]);

            return;
        }

        $ragQueryText = trim($email->anonymized_subject.' '.$email->anonymized_body);
        $queryEmbedding = $embeddingBackend->embed($ragQueryText);
        $ragExamples = $vectorStore->findSimilar($queryEmbedding, limit: 5, excludeEmailId: $email->id);

        $request = new TriageRequest(
            anonymizedSubject: $email->anonymized_subject ?? '',
            anonymizedBody: $email->anonymized_body ?? '',
            senderDomain: $email->sender_domain,
            availableCategories: $categoryResolver->activeOptionsForPrompt()->all(),
            ragExamples: $ragExamples,
            senderReputation: $reputationService->summaryFor($email->sender_email),
        );

        $backend = $triageBackend->identifier();
        $rep = $request->senderReputation;

        PipelineLog::record($email->id, 'triage', 'started', $this->attempts(), payload: [
            'config' => [
                'backend' => $backend,
                'model' => config("triage.{$backend}.model"),
                'timeout' => config('triage.timeout'),
                'temperature' => config('triage.temperature'),
                'default_confidence_threshold' => config('triage.default_confidence_threshold'),
            ],
            'inputs' => [
                'sender_domain' => $request->senderDomain,
                'categories' => array_map(fn ($c) => $c->name, $request->availableCategories),
                'rag_example_count' => count($ragExamples),
                'rag_examples' => array_map(fn ($ex) => [
                    'email_id' => $ex->emailId,
                    'similarity' => $ex->similarityScore,
                    'category' => $ex->categoryName,
                ], $ragExamples),
                'sender_reputation' => $rep ? [
                    'email_count' => $rep->emailCount,
                    'most_common_category' => $rep->mostCommonCategory,
                    'most_common_action' => $rep->mostCommonAction,
                ] : null,
            ],
        ]);

        $start = microtime(true);

        try {
            $response = $triageBackend->triage($request);
            $ragContextEmailIds = array_map(fn ($ex) => $ex->emailId, $ragExamples);

            $triage = DB::transaction(function () use ($email, $response, $categoryResolver, $reputationService, $ragContextEmailIds) {
                $category = $categoryResolver->resolve($response);
                $status = $categoryResolver->routeStatus($response, $category);

                // A proposal is only recorded when the email actually resolved to a
                // pending-review (new) category. Both columns are sourced from the
                // same proposal so they can never diverge — no orphan reasoning.
                $proposal = ($category && $category->status === 'pending_review') ? $response->categoryProposal : null;

                $triage = TriageResult::create([
                    'email_id' => $email->id,
                    'category_id' => $category && $category->status === 'active' ? $category->id : null,
                    'proposed_category_name' => $proposal?->name,
                    'proposed_category_reasoning' => $proposal?->reasoning,
                    'summary' => $response->summary,
                    'triage_reasoning' => $response->triageReasoning,
                    'urgency' => $response->urgency,
                    'confidence' => $response->confidence,
                    'status' => $status,
                    'suggested_action' => $response->suggestedAction,
                    'suggested_reply_draft' => $response->suggestedReplyDraft,
                    'llm_backend' => $response->llmBackend,
                    'llm_model' => $response->llmModel,
                    'raw_llm_response' => $response->rawResponse,
                    'rag_context_email_ids' => $ragContextEmailIds,

                    // Immutable snapshot of the LLM's ORIGINAL output. Unlike
                    // category_id (active-only), llm_category_id keeps the resolved
                    // category even for proposed/pending-review ones, so the original
                    // prediction is always nameable. Never overwritten by corrections.
                    'llm_category_id' => $category?->id,
                    'llm_urgency' => $response->urgency,
                    'llm_suggested_action' => $response->suggestedAction,
                ]);

                $reputationService->recordTriage($email, $triage);

                return $triage;
            });

            // Written AFTER the transaction commits, so a rollback can never
            // leave an orphan 'succeeded' row. The raw LLM response lives here
            // (and is also on triage_results), giving a full picture on success.
            PipelineLog::record(
                $email->id,
                'triage',
                'succeeded',
                $this->attempts(),
                payload: [
                    'outputs' => [
                        'llm_backend' => $response->llmBackend,
                        'llm_model' => $response->llmModel,
                        'matched_category_id' => $response->matchedCategoryId,
                        'confidence' => $response->confidence,
                        'urgency' => $response->urgency,
                        'suggested_action' => $response->suggestedAction,
                        'status' => $triage->status->value,
                    ],
                    'response' => $response->rawResponse,
                ],
                durationMs: $this->elapsedMs($start),
                triageResultId: $triage->id,
            );

            Log::info('Email triaged', [
                'email_id' => $email->id,
                'status' => $triage->status->value,
                'confidence' => $triage->confidence,
            ]);

            EmbedAndStoreJob::dispatch($email->id, $queryEmbedding);
        } catch (\Throwable $e) {
            PipelineLog::record($email->id, 'triage', 'failed', $this->attempts(), $e->getMessage(), [
                'error' => $e->getMessage(),
            ], $this->elapsedMs($start));

            throw $e;
        }
    }

    private function elapsedMs(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Triage job failed', ['email_id' => $this->emailId, 'error' => $exception->getMessage()]);
    }
}
