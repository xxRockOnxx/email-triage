<?php

namespace App\Jobs;

use App\Contracts\AnonymizerContract;
use App\Models\Email;
use App\Models\PiiMapping;
use App\Models\PipelineLog;
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
            PipelineLog::record($email->id, 'anonymize', 'skipped', $this->attempts(), 'already anonymized');

            return; // idempotent — already processed
        }

        PipelineLog::record($email->id, 'anonymize', 'started', $this->attempts(), payload: [
            'config' => [
                'analyzer_url' => config('presidio.analyzer_url'),
                'anonymizer_url' => config('presidio.anonymizer_url'),
                'score_threshold' => config('presidio.score_threshold'),
                'entities' => config('presidio.entities'),
            ],
            'inputs' => [
                'subject_chars' => mb_strlen($email->subject_enc ?? ''),
                'body_chars' => mb_strlen($email->body_enc ?? ''),
            ],
        ]);

        $start = microtime(true);

        try {
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

            $mappingCount = count($subjectResult->mappings) + count($bodyResult->mappings);

            PipelineLog::record($email->id, 'anonymize', 'succeeded', $this->attempts(), payload: [
                'outputs' => [
                    'pii_mapping_count' => $mappingCount,
                    'anonymized_subject_chars' => mb_strlen($subjectResult->anonymizedText),
                    'anonymized_body_chars' => mb_strlen($bodyResult->anonymizedText),
                ],
            ], durationMs: $this->elapsedMs($start));

            Log::info('Email anonymized', ['email_id' => $email->id]);
        } catch (\Throwable $e) {
            PipelineLog::record($email->id, 'anonymize', 'failed', $this->attempts(), $e->getMessage(), [
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
        Log::error('Anonymize job failed', ['email_id' => $this->emailId, 'error' => $exception->getMessage()]);
    }
}
