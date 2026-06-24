<?php

namespace App\Services\Vector;

use App\Contracts\VectorStoreContract;
use App\DTOs\RagExample;
use App\Models\Email;
use Illuminate\Support\Facades\DB;

/**
 * Wraps the pgvector `email_embeddings` table. Eloquent doesn't natively
 * understand the `vector` column type's operators, so similarity search
 * uses raw SQL with the `<=>` cosine-distance operator (lower = more similar).
 * Only emails with a confirmed (auto_filed or corrected) triage result are
 * eligible as RAG examples — "needs_review" results haven't been validated
 * yet and shouldn't teach the model anything.
 */
class PgVectorStore implements VectorStoreContract
{
    public function upsert(int $emailId, array $embedding): void
    {
        $vectorLiteral = $this->toVectorLiteral($embedding);

        DB::statement(
            'INSERT INTO email_embeddings (email_id, embedding, updated_at)
             VALUES (?, ?::vector, now())
             ON CONFLICT (email_id)
             DO UPDATE SET embedding = excluded.embedding, updated_at = now()',
            [$emailId, $vectorLiteral]
        );
    }

    public function findSimilar(array $embedding, int $limit = 5, ?int $excludeEmailId = null): array
    {
        $vectorLiteral = $this->toVectorLiteral($embedding);

        $exclusionClause = $excludeEmailId ? 'AND ee.email_id != ?' : '';
        $bindings = [$vectorLiteral];
        if ($excludeEmailId) {
            $bindings[] = $excludeEmailId;
        }
        $bindings[] = $limit * 3;

        $rows = DB::select(
            "WITH query_vec AS (SELECT ?::vector AS vec)
            SELECT ee.email_id, (ee.embedding <=> qv.vec) AS distance
            FROM email_embeddings ee, query_vec qv
            WHERE 1=1 {$exclusionClause}
            ORDER BY ee.embedding <=> qv.vec ASC
            LIMIT ?",
            $bindings  // now only one $vectorLiteral needed
        );

        if (empty($rows)) {
            return [];
        }

        $emailIds = array_column($rows, 'email_id');
        $distanceByEmailId = array_column($rows, 'distance', 'email_id');

        $emails = Email::withTrashed()
            ->with(['latestTriageResult.category', 'latestTriageResult.llmCategory'])
            ->whereIn('id', $emailIds)
            ->whereHas('latestTriageResult', fn ($q) => $q->whereIn('status', ['auto_filed', 'corrected']))
            ->get()
            ->keyBy('id');

        $examples = [];
        foreach ($emailIds as $emailId) {
            if (count($examples) >= $limit) {
                break;
            }

            $email = $emails->get($emailId);
            if (! $email || ! $email->latestTriageResult) {
                continue;
            }

            $triage = $email->latestTriageResult;
            $distance = (float) ($distanceByEmailId[$emailId] ?? 1.0);

            $examples[] = new RagExample(
                emailId: $email->id,
                anonymizedSubject: $email->anonymized_subject ?? '',
                anonymizedSummary: $triage->summary,
                categoryName: $triage->category?->name ?? $triage->proposed_category_name ?? 'Uncategorized',
                urgency: $triage->urgency->value,
                suggestedAction: $triage->suggested_action->value,
                originalLlmCategory: $triage->llmCategory?->name ?? $triage->proposed_category_name ?? 'Uncategorized',
                originalLlmUrgency: $triage->llm_urgency->value,
                originalLlmAction: $triage->llm_suggested_action->value,
                similarityScore: 1 - $distance, // cosine distance -> similarity (0..1, higher = closer)
                wasUserCorrected: $triage->status->value === 'corrected',
            );
        }

        return $examples;
    }

    public function delete(int $emailId): void
    {
        DB::statement('DELETE FROM email_embeddings WHERE email_id = ?', [$emailId]);
    }

    /**
     * pgvector accepts vector input as a string literal like '[0.1,0.2,0.3]'.
     */
    private function toVectorLiteral(array $embedding): string
    {
        return '['.implode(',', array_map(fn ($v) => (string) (float) $v, $embedding)).']';
    }
}
