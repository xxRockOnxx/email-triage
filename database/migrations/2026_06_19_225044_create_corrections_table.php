<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every user correction is the core training signal for the RAG memory and
        // sender reputation. Never overwrite triage_results in place — append here instead,
        // so we keep a full history of what the model got wrong.
        Schema::create('corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('triage_result_id')->constrained()->cascadeOnDelete();

            $table->foreignId('old_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('new_category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('old_urgency')->nullable();
            $table->string('new_urgency')->nullable();

            $table->string('old_suggested_action')->nullable();
            $table->string('new_suggested_action')->nullable();

            $table->text('note')->nullable(); // optional free-text reason from the user

            $table->timestamp('corrected_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corrections');
    }
};
