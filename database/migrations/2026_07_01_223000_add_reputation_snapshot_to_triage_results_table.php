<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('triage_results', function (Blueprint $table) {
            // Snapshot of {action, category_name, urgency_weight, confidence} last
            // folded into sender_stats for this row. Lets a correction reverse the
            // exact prior contribution (not just the LLM's original guess, which
            // would be wrong to reverse against on a second correction) instead of
            // double-counting into the sender reputation histograms.
            $table->json('reputation_snapshot')->nullable()->after('rag_context_email_ids');
        });
    }

    public function down(): void
    {
        Schema::table('triage_results', function (Blueprint $table) {
            $table->dropColumn('reputation_snapshot');
        });
    }
};
