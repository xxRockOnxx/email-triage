<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('llm_category_id')->nullable()->constrained('categories')->nullOnDelete();

            // If the LLM proposed a brand-new category not yet in the categories table,
            // it lands here pending review rather than auto-creating.
            $table->string('proposed_category_name')->nullable();
            $table->text('proposed_category_reasoning')->nullable();

            $table->text('summary');

            $table->enum('urgency', ['low', 'medium', 'high', 'critical']);
            $table->enum('llm_urgency', ['low', 'medium', 'high', 'critical']);

            // 0-100, the LLM's self-reported confidence in this triage
            $table->unsignedTinyInteger('confidence');

            // Routing outcome derived from confidence vs category threshold
            $table->enum('status', ['auto_filed', 'needs_review', 'corrected'])->default('needs_review');

            $table->enum('suggested_action', ['reply', 'archive', 'delete', 'flag', 'none']);
            $table->enum('llm_suggested_action', ['reply', 'archive', 'delete', 'flag', 'none']);

            $table->text('suggested_reply_draft')->nullable();

            // Which LLM backend/model produced this, for auditing / comparing backends
            $table->string('llm_backend')->nullable();
            $table->string('llm_model')->nullable();

            // Full raw structured response from the LLM, for debugging bad triages
            $table->json('raw_llm_response')->nullable();

            // RAG context actually used for this triage (ids of similar past emails), for auditing
            $table->json('rag_context_email_ids')->nullable();

            $table->timestamps();

            $table->index(['status', 'urgency']);
            $table->index('confidence');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_results');
    }
};
