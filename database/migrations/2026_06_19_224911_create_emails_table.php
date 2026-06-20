<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails', function (Blueprint $table) {
            $table->id();

            $table->string('gmail_id')->unique(); // Message-ID header, fetched via IMAP
            $table->string('thread_id')->index();  // X-GM-THRID when available, else derived

            $table->string('sender_email')->index();
            $table->string('sender_name')->nullable();
            $table->string('sender_domain')->index();

            // Encrypted at rest — real content. Cast to `encrypted` on the model.
            $table->text('subject_enc')->nullable();
            $table->longText('body_enc')->nullable();

            // Plaintext, PII-stripped copies. Safe to index, search, embed, and send to LLMs.
            $table->text('anonymized_subject')->nullable();
            $table->longText('anonymized_body')->nullable();

            $table->jsonb('gmail_labels')->nullable();   // labels/flags observed via IMAP
            $table->jsonb('gmail_headers')->nullable();  // selected headers worth keeping (List-Unsubscribe, etc.)

            $table->boolean('is_anonymized')->default(false);
            $table->timestamp('anonymized_at')->nullable();

            $table->timestamp('received_at')->index();
            $table->timestamp('polled_at')->nullable();

            $table->timestamps();

            $table->index(['received_at', 'thread_id']);
        });

        // Generated tsvector column for full-text search over the anonymized
        // (PII-stripped) content only — never index subject_enc/body_enc,
        // both because they're encrypted ciphertext and because indexing
        // real PII for search would defeat the anonymization layer entirely.
        // STORED generated columns keep themselves in sync automatically;
        // no observer/trigger needed, unlike the old SQLite FTS5 approach.
        DB::statement(<<<'SQL'
            ALTER TABLE emails ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                setweight(to_tsvector('english', coalesce(anonymized_subject, '')), 'A') ||
                setweight(to_tsvector('english', coalesce(anonymized_body, '')), 'B')
            ) STORED;
        SQL);

        DB::statement('CREATE INDEX emails_search_vector_idx ON emails USING GIN (search_vector);');
    }

    public function down(): void
    {
        Schema::dropIfExists('emails');
    }
};
