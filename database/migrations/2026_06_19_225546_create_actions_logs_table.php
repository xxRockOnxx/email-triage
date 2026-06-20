<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triage_result_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('action_type', ['reply_draft_created', 'archive', 'delete', 'flag', 'label_applied', 'undo']);

            // Was this auto-executed (high confidence) or user-approved?
            $table->enum('initiated_by', ['auto', 'user'])->default('user');

            $table->json('payload')->nullable(); // e.g. gmail draft id, label id applied, etc.

            $table->string('gmail_action_id')->nullable(); // draft id / message id returned by Gmail API

            $table->timestamp('executed_at');
            $table->timestamp('undone_at')->nullable();

            $table->timestamps();

            $table->index(['action_type', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions_log');
    }
};
