<?php

namespace App\Contracts;

use App\DTOs\RagExample;

interface VectorStoreContract
{
    /**
     * @param  float[]  $embedding
     */
    public function upsert(int $emailId, array $embedding): void;

    /**
     * Find the k most similar past emails by embedding distance, returning
     * them as ready-to-use RAG few-shot examples (only emails with a
     * human-confirmed or auto-filed triage result are eligible).
     *
     * @param  float[]  $embedding
     * @return RagExample[]
     */
    public function findSimilar(array $embedding, int $limit = 5, ?int $excludeEmailId = null): array;

    public function delete(int $emailId): void;
}
