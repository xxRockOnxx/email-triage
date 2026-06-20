<?php

namespace App\DTOs;

/**
 * Result of running text through the Presidio anonymize service.
 * `mappings` is the reversible placeholder -> original PII map; it must
 * be persisted to pii_mappings (encrypted) and NEVER forwarded to an LLM.
 */
class AnonymizationResult
{
    /**
     * @param  PiiMapping[]  $mappings
     */
    public function __construct(
        public readonly string $anonymizedText,
        public readonly array $mappings,
    ) {}

    public static function fromPresidioResponse(string $anonymizedText, array $entities): self
    {
        $mappings = array_map(
            fn (array $entity) => new PiiMapping(
                placeholder: $entity['placeholder'],
                entityType: $entity['entity_type'],
                originalValue: $entity['original_value'],
                detectionScore: $entity['score'] ?? null,
            ),
            $entities
        );

        return new self($anonymizedText, $mappings);
    }
}
