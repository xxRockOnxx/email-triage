<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->enum('pipeline_status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')
                ->after('anonymized_at');
            $table->index('pipeline_status');
        });

        // Backfill existing rows from their current state. Priority order:
        // a triaged email → completed; otherwise a recorded failure → failed;
        // anything left entered the chain but never settled → processing.
        DB::table('emails')
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('triage_results')
                ->whereColumn('triage_results.email_id', 'emails.id'))
            ->update(['pipeline_status' => 'completed']);

        DB::table('emails')
            ->where('pipeline_status', 'pending')
            ->whereExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('pipeline_logs')
                ->whereColumn('pipeline_logs.email_id', 'emails.id')
                ->where('pipeline_logs.status', 'failed'))
            ->update(['pipeline_status' => 'failed']);

        DB::table('emails')
            ->where('pipeline_status', 'pending')
            ->update(['pipeline_status' => 'processing']);
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            $table->dropIndex('emails_pipeline_status_index');
            $table->dropColumn('pipeline_status');
        });
    }
};
