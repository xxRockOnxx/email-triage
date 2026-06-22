<?php

namespace App\Jobs;

use App\Contracts\AnonymizerContract;
use App\Models\Email;
use App\Models\PiiMapping;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline stage 2: runs the (decrypted, real) subject/body through Presidio,
 * stores the anonymized plaintext copies + the reversible PII mapping
 * (encrypted), then dispatches triage. This is the highest-risk-of-bugs
 * stage — get this right before anything downstream is trustworthy.
 */
class AnonymizeEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 15;

    public function __construct(private readonly int $emailId) {}

    public function handle(AnonymizerContract $anonymizer): void
    {
        $email = Email::findOrFail($this->emailId);

        if ($email->is_anonymized) {
            return; // idempotent — already processed
        }

        $subjectResult = $anonymizer->anonymize($email->subject_enc ?? '');
        $bodyResult = $anonymizer->anonymize($email->body_enc ?? '');

        DB::transaction(function () use ($email, $subjectResult, $bodyResult) {
            $email->update([
                'anonymized_subject' => $subjectResult->anonymizedText,
                'anonymized_body' => $bodyResult->anonymizedText,
                'is_anonymized' => true,
                'anonymized_at' => now(),
            ]);

            foreach ([$subjectResult, $bodyResult] as $result) {
                foreach ($result->mappings as $mapping) {
                    PiiMapping::updateOrCreate(
                        ['email_id' => $email->id, 'placeholder' => $mapping->placeholder],
                        [
                            'entity_type' => $mapping->entityType,
                            'original_value_enc' => $mapping->originalValue,
                            'detection_score' => $mapping->detectionScore,
                        ]
                    );
                }
            }
        });

        Log::info('Email anonymized', ['email_id' => $email->id]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Anonymize job failed', ['email_id' => $this->emailId, 'error' => $exception->getMessage()]);
    }
}
