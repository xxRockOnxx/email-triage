<?php

namespace App\Contracts;

use App\DTOs\AnonymizationResult;

interface AnonymizerContract
{
    /**
     * Detect and replace PII in the given text with consistent, reversible
     * placeholders (e.g. PERSON_1, EMAIL_ADDRESS_1). Returns both the
     * anonymized text and the placeholder->original mapping.
     */
    public function anonymize(string $text): AnonymizationResult;

    /**
     * Reverse anonymization given previously-generated mappings — used to
     * restore real names/details in LLM output before showing it in the UI.
     *
     * @param  \App\DTOs\PiiMapping[]  $mappings
     */
    public function deanonymize(string $text, array $mappings): string;
}
