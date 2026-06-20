<?php

namespace App\DTOs;

/**
 * Everything a TriageBackend needs to classify an email. Note: only ever
 * carries anonymized text + non-identifying metadata (domain, not full address).
 */
class TriageRequest
{
    /**
     * @param  CategoryOption[]  $availableCategories
     * @param  RagExample[]  $ragExamples
     */
    public function __construct(
        public readonly string $anonymizedSubject,
        public readonly string $anonymizedBody,
        public readonly string $senderDomain,
        public readonly array $availableCategories,
        public readonly array $ragExamples,
        public readonly ?SenderReputationSummary $senderReputation = null,
    ) {}
}
