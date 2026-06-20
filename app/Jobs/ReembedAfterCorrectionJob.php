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
 * Re-embeds an email after a user correction. The embedding itself doesn't
 * change (text is the same), but this ensures the email is (re)indexed now
 * that it has a 'corrected' triage status, which makes it eligible as a
 * high-trust RAG example (see VectorStoreContract::findSimilar eligibility
 * filter and AbstractTriageBackend's "trust this label strongly" prompt note).
 */
class ReembedAfterCorrectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $emailId) {}

    public function handle(EmbeddingBackendContract $embeddingBackend, VectorStoreContract $vectorStore): void
    {
        $email = Email::findOrFail($this->emailId);

        $embedding = $embeddingBackend->embed(trim($email->anonymized_subject.' '.$email->anonymized_body));
        $vectorStore->upsert($email->id, $embedding);

        Log::info('Email re-embedded after correction', ['email_id' => $email->id]);
    }
}
