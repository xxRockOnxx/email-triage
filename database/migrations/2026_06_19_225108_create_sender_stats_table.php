<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sender_stats', function (Blueprint $table) {
            $table->id();
            $table->string('sender_email')->unique();
            $table->string('sender_domain')->index();

            $table->unsignedInteger('email_count')->default(0);

            // { "category_name": count, ... } — cheap histogram, rebuilt incrementally
            $table->json('category_histogram')->nullable();

            $table->float('avg_urgency_score')->nullable(); // low=1..critical=4 averaged
            $table->float('avg_confidence')->nullable();

            // { "archive": 5, "reply": 1, ... }
            $table->json('action_histogram')->nullable();

            // Most recent N actions, most recent first: [{action, at, category}]
            $table->json('recent_actions')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('stats_updated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_stats');
    }
};
