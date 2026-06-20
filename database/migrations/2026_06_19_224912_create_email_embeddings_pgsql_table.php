<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Requires the pgvector extension installed on the Postgres server
     * (`CREATE EXTENSION vector;` — bundled with the pgvector/pgvector
     * docker image, or installable via your distro's postgresql-*-pgvector
     * package). Dimension must match your embedding model's output size —
     * keep in sync with config/embedding.php. Changing the dimension later
     * requires dropping and recreating this table (and re-embedding all
     * emails).
     */
    public function up(): void
    {
        $dimensions = config('embedding.dimensions', 768);

        DB::statement(<<<SQL
            CREATE TABLE email_embeddings (
                email_id BIGINT PRIMARY KEY REFERENCES emails(id) ON DELETE CASCADE,
                embedding vector({$dimensions}) NOT NULL,
                created_at TIMESTAMP DEFAULT now(),
                updated_at TIMESTAMP DEFAULT now()
            );
        SQL);

        // HNSW index for fast approximate nearest-neighbor search using cosine
        // distance. IVFFlat is the other common choice, but HNSW generally
        // gives better recall/speed tradeoffs at the scale a single mailbox
        // will realistically reach, and doesn't need a training/list-count step.
        DB::statement(
            'CREATE INDEX email_embeddings_hnsw_idx ON email_embeddings
             USING hnsw (embedding vector_cosine_ops);'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS email_embeddings;');
    }
};
