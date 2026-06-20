<?php

namespace App\Services\Anonymization;

use App\Contracts\AnonymizerContract;
use App\DTOs\AnonymizationResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the official Presidio docker images over HTTP, both running
 * locally (e.g. via docker-compose): presidio-analyzer + presidio-anonymizer.
 * Nothing here ever leaves localhost.
 */
class PresidioAnonymizer implements AnonymizerContract
{
    public function __construct(
        private string $analyzerUrl = '',
        private string $anonymizerUrl = '',
        private readonly string $language = 'en',
    ) {
        $this->analyzerUrl = $analyzerUrl ?: config('presidio.analyzer_url');
        $this->anonymizerUrl = $anonymizerUrl ?: config('presidio.anonymizer_url');
    }

    public function anonymize(string $text): AnonymizationResult
    {
        if (trim($text) === '') {
            return new AnonymizationResult('', []);
        }

        $entities = $this->analyze($text);

        if (empty($entities)) {
            return new AnonymizationResult($text, []);
        }

        [$anonymizedText, $entityDetails] = $this->buildPlaceholderAnonymization($text, $entities);

        return AnonymizationResult::fromPresidioResponse($anonymizedText, $entityDetails);
    }

    /**
     * Calls presidio-analyzer to detect PII entities and their positions.
     */
    private function analyze(string $text): array
    {
        $response = Http::baseUrl($this->analyzerUrl)
            ->timeout(config('presidio.timeout', 15))
            ->post('/analyze', [
                'text' => $text,
                'language' => $this->language,
                'entities' => config('presidio.entities'), // null = all default recognizers
                'score_threshold' => config('presidio.score_threshold', 0.4),
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Presidio analyzer request failed: {$response->status()} {$response->body()}");
        }

        return $response->json();
    }

    /**
     * We build placeholders ourselves (rather than using presidio-anonymizer's
     * built-in "replace" operator) so we get consistent numbering per entity
     * type AND can capture the exact original substring per occurrence for
     * reversible storage in pii_mappings.
     *
     * @return array{0: string, 1: array}
     */
    private function buildPlaceholderAnonymization(string $text, array $entities): array
    {
        // Sort by start offset descending so we can replace in-place without
        // invalidating subsequent offsets.
        usort($entities, fn ($a, $b) => $b['start'] <=> $a['start']);

        $placeholderCounters = [];
        $placeholderForValue = []; // "PERSON:john@x.com" => "PERSON_1" — reuse placeholder for repeated values
        $entityDetails = [];
        $result = $text;

        foreach ($entities as $entity) {
            $entityType = $entity['entity_type'];
            $originalValue = mb_substr($text, $entity['start'], $entity['end'] - $entity['start']);
            $dedupeKey = $entityType.':'.$originalValue;

            if (! isset($placeholderForValue[$dedupeKey])) {
                $placeholderCounters[$entityType] = ($placeholderCounters[$entityType] ?? 0) + 1;
                $placeholderForValue[$dedupeKey] = "{$entityType}_{$placeholderCounters[$entityType]}";
            }

            $placeholder = $placeholderForValue[$dedupeKey];

            $result = mb_substr($result, 0, $entity['start'])
                .'['.$placeholder.']'
                .mb_substr($result, $entity['end']);

            $entityDetails[] = [
                'placeholder' => $placeholder,
                'entity_type' => $entityType,
                'original_value' => $originalValue,
                'score' => $entity['score'] ?? null,
            ];
        }

        // Collapse duplicate detail entries (same placeholder appearing multiple times).
        $uniqueDetails = collect($entityDetails)->unique('placeholder')->values()->all();

        return [$result, $uniqueDetails];
    }

    public function deanonymize(string $text, array $mappings): string
    {
        $result = $text;

        foreach ($mappings as $mapping) {
            $result = str_replace(
                '['.$mapping->placeholder.']',
                $mapping->originalValue,
                $result
            );
        }

        return $result;
    }
}
