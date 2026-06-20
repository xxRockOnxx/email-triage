<?php

namespace App\Jobs;

use App\Contracts\EmbeddingBackendContract;
use App\Contracts\VectorStoreContract;
use App\Models\Email;
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

        $embedding = $this->precomputedEmbedding
            ?? $embeddingBackend->embed(trim($email->anonymized_subject.' '.$email->anonymized_body));

        $vectorStore->upsert($email->id, $embedding);

        Log::info('Email embedded and stored', ['email_id' => $email->id]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Embed job failed', ['email_id' => $this->emailId, 'error' => $exception->getMessage()]);
    }
}
