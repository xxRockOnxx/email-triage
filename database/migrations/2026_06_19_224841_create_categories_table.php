<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();

            // 'gmail'  => seeded from Gmail's own category labels (CATEGORY_PERSONAL, etc.)
            // 'llm'    => proposed by the triage LLM, pending or accepted
            // 'user'   => manually created by the user
            $table->enum('source', ['gmail', 'llm', 'user'])->default('user');

            // Gmail's raw label id this maps to, if source = gmail (e.g. CATEGORY_PROMOTIONS)
            $table->string('gmail_label_id')->nullable();

            // LLM-proposed categories start pending; only used in prompts/matching once accepted.
            $table->enum('status', ['active', 'pending_review', 'rejected', 'merged'])->default('active');

            // If this category was merged into another to avoid sprawl (e.g. "Invoice" -> "Receipt/Invoice")
            $table->foreignId('merged_into_id')->nullable()->constrained('categories')->nullOnDelete();

            // Optional hierarchy: LLM-proposed categories can nest under a Gmail-seeded parent.
            $table->foreignId('parent_category_id')->nullable()->constrained('categories')->nullOnDelete();

            // Per-category confidence threshold for auto-filing vs needs_review routing.
            // Null = fall back to the global default in config/triage.php
            $table->unsignedTinyInteger('confidence_threshold')->nullable();

            $table->boolean('is_system_default')->default(false);

            $table->timestamps();

            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
