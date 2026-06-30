<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured, per-email processing log for the triage pipeline. One row per
 * stage attempt (anonymize / triage / embed) per job retry, recording what was
 * configured, what was sent/received, and — crucially — why a stage failed.
 * Mirrors actions_log (which tracks user actions); this tracks pipeline
 * internals so a silently-untriaged email has a debuggable trail in the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triage_result_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('stage', ['anonymize', 'triage', 'embed']);
            $table->enum('status', ['started', 'succeeded', 'failed', 'skipped']);

            // Which queue attempt wrote this row (jobs retry up to 3×), so
            // transient vs persistent failures are distinguishable in the log.
            $table->unsignedInteger('attempt')->nullable();

            $table->string('message')->nullable();

            // Shape varies by stage/status: { config, inputs, outputs, response,
            // error }. All content is already-anonymized text — no raw PII.
            $table->json('payload')->nullable();

            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('recorded_at');

            $table->timestamps();

            $table->index(['email_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_logs');
    }
};
