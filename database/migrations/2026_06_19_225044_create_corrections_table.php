<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One correction per triage_result, updated in place on re-correction
        // (misclick, added note) rather than appending — so the training signal
        // stays clean. old_* snapshots the model's ORIGINAL prediction; new_* is the
        // user's final answer. created_at = first correction, updated_at = latest.
        Schema::create('corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('triage_result_id')->constrained()->cascadeOnDelete();
            $table->unique('triage_result_id');

            $table->foreignId('old_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('new_category_id')->nullable()->constrained('categories')->nullOnDelete();

            $table->string('old_urgency')->nullable();
            $table->string('new_urgency')->nullable();

            $table->string('old_suggested_action')->nullable();
            $table->string('new_suggested_action')->nullable();

            $table->text('note')->nullable(); // optional free-text reason from the user

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corrections');
    }
};
