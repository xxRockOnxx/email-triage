<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per stage attempt of an email's processing pipeline. Written by the
 * pipeline jobs (AnonymizeEmailJob, TriageEmailJob, EmbedAndStoreJob) to leave
 * a debuggable trail — what was configured/sent/received and, on failure, why.
 */
class PipelineLog extends Model
{
    protected $table = 'pipeline_logs';

    protected $fillable = [
        'email_id',
        'triage_result_id',
        'stage',
        'status',
        'attempt',
        'message',
        'payload',
        'duration_ms',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function triageResult(): BelongsTo
    {
        return $this->belongsTo(TriageResult::class);
    }

    /**
     * Centralized writer so every call site records a row in one line with a
     * consistent shape. stamped with recorded_at = now() implicitly.
     */
    public static function record(
        int $emailId,
        string $stage,
        string $status,
        ?int $attempt = null,
        ?string $message = null,
        ?array $payload = null,
        ?int $durationMs = null,
        ?int $triageResultId = null,
    ): self {
        return self::create([
            'email_id' => $emailId,
            'triage_result_id' => $triageResultId,
            'stage' => $stage,
            'status' => $status,
            'attempt' => $attempt,
            'message' => $message,
            'payload' => $payload,
            'duration_ms' => $durationMs,
            'recorded_at' => now(),
        ]);
    }
}
