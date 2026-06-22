<?php

namespace App\Jobs;

use App\Contracts\EmbeddingBackendContract;
use App\Contracts\TriageBackendContract;
use App\Contracts\VectorStoreContract;
use App\DTOs\TriageRequest;
use App\Models\Email;
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

        Log::info('Email triaged', [
            'email_id' => $email->id,
            'status' => $triage->status->value,
            'confidence' => $triage->confidence,
        ]);

        EmbedAndStoreJob::dispatch($email->id, $queryEmbedding);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Triage job failed', ['email_id' => $this->emailId, 'error' => $exception->getMessage()]);
    }
}
