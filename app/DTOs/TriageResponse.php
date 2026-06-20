<?php

namespace App\DTOs;

/**
 * Structured output parsed from the LLM's triage response. Backends are
 * responsible for parsing their provider's response format into this shape.
 */
class TriageResponse
{
    public function __construct(
        public readonly ?int $matchedCategoryId,
        public readonly ?string $proposedCategoryName,
        public readonly ?string $proposedCategoryReasoning,
        public readonly string $summary,
        public readonly string $urgency,        // matches Urgency enum value
        public readonly int $confidence,         // 0-100
        public readonly string $suggestedAction, // matches SuggestedAction enum value
        public readonly ?string $suggestedReplyDraft,
        public readonly string $llmBackend,
        public readonly string $llmModel,
        public readonly array $rawResponse,
    ) {}

    public function isNewCategoryProposal(): bool
    {
        return $this->matchedCategoryId === null && $this->proposedCategoryName !== null;
    }
}
