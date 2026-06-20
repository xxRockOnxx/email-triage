<?php

namespace App\DTOs;

/**
 * A past, human-confirmed triage result retrieved via vector similarity,
 * injected as few-shot context so the LLM learns the user's actual preferences
 * (not just generic classification).
 */
class RagExample
{
    public function __construct(
        public readonly int $emailId,
        public readonly string $anonymizedSubject,
        public readonly string $anonymizedSummary,
        public readonly string $categoryName,
        public readonly string $urgency,
        public readonly string $suggestedAction,
        public readonly float $similarityScore,
        public readonly bool $wasUserCorrected = false,
    ) {}
}
