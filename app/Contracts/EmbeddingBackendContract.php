<?php

namespace App\Contracts;

interface EmbeddingBackendContract
{
    /**
     * Embed a piece of text into a fixed-dimension float vector.
     * Dimension must match config('embedding.dimensions') and the
     * email_embeddings pgvector column schema.
     *
     * @return float[]
     */
    public function embed(string $text): array;

    public function dimensions(): int;
}
