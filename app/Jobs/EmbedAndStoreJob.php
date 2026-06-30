<?php

namespace App\Jobs;

use App\Contracts\EmbeddingBackendContract;
use App\Contracts\VectorStoreContract;
use App\Models\Email;
use App\Models\PipelineLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline stage 4 (final): stores the email's embedding in the vector store
 * so it becomes available as RAG context for future triage. Accepts an
 * optional pre-computed embedding (TriageEmailJob already computed one for
 * retrieval) to avoid embedding the same text twice.
 */
class EmbedAndStoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 15;

    /**
     * @param  float[]|null  $precomputedEmbedding
     */
    public function __construct(
        private readonly int $emailId,
        private readonly ?array $precomputedEmbedding = null,
    ) {}

    public function handle(EmbeddingBackendContract $embeddingBackend, VectorStoreContract $vectorStore): void
    {
        $email = Email::findOrFail($this->emailId);

        $backend = config('embedding.backend');

        PipelineLog::record($email->id, 'embed', 'started', $this->attempts(), payload: [
            'config' => [
                'backend' => $backend,
                'model' => config("embedding.{$backend}.model"),
                'dimensions' => config('embedding.dimensions'),
                'timeout' => config('embedding.timeout'),
            ],
            'inputs' => [
                'text_chars' => mb_strlen(trim($email->anonymized_subject.' '.$email->anonymized_body)),
                'precomputed' => $this->precomputedEmbedding !== null,
            ],
        ]);

        $start = microtime(true);

        try {
            $embedding = $this->precomputedEmbedding
                ?? $embeddingBackend->embed(trim($email->anonymized_subject.' '.$email->anonymized_body));

            $vectorStore->upsert($email->id, $embedding);

            PipelineLog::record($email->id, 'embed', 'succeeded', $this->attempts(), payload: [
                'outputs' => [
                    'dimensions' => count($embedding),
                ],
            ], durationMs: $this->elapsedMs($start));

            Log::info('Email embedded and stored', ['email_id' => $email->id]);
        } catch (\Throwable $e) {
            PipelineLog::record($email->id, 'embed', 'failed', $this->attempts(), $e->getMessage(), [
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
        Log::error('Embed job failed', ['email_id' => $this->emailId, 'error' => $exception->getMessage()]);
    }
}
