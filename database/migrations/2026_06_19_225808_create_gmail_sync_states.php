<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single-row (practically) table tracking the IMAP polling cursor.
     * sync_cursor stores an ISO8601 timestamp of the last successful poll —
     * IMAP has no true incremental cursor like Gmail API's historyId, so
     * "incremental" here means "messages received since this timestamp."
     */
    public function up(): void
    {
        Schema::create('gmail_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('gmail_account_email')->unique();
            $table->string('sync_cursor')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->enum('status', ['idle', 'polling', 'error'])->default('idle');
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_sync_states');
    }
};
