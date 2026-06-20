<?php

return [
    // Which EmbeddingBackendContract implementation to bind: ollama or openai.
    'backend' => env('EMBEDDING_BACKEND', 'ollama'),

    'timeout' => env('EMBEDDING_TIMEOUT', 30),

    // Must match the bound backend's actual output dimension AND the
    // email_embeddings pgvector column schema (migration
    // 2024_01_01_000009_create_email_embeddings_table.php). If you change
    // this after the table is created, you must drop and recreate the
    // email_embeddings table (and re-embed all emails) — dimension is fixed at creation.
    'dimensions' => env('EMBEDDING_DIMENSIONS', 768),

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_EMBEDDING_MODEL', 'nomic-embed-text'), // 768 dims
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'), // 1536 dims
    ],
];
