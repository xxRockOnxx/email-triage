<?php

namespace App\Services\Triage;

use App\Contracts\TriageBackendContract;
use App\DTOs\CategoryProposal;
use App\DTOs\TriageRequest;
use App\Enums\SuggestedAction;
use App\Enums\Urgency;

abstract class AbstractTriageBackend implements TriageBackendContract
{
    /**
     * Shared structured-output schema description, used in the system prompt
     * across backends so all of them are asked for the same shape.
     */
    protected function systemPrompt(TriageRequest $request): string
    {
        $categorySection = $this->formatCategoryInstructions($request);
        $ragSection = $this->formatRagExamples($request);
        $reputationSection = $this->formatReputation($request);

        return <<<PROMPT
            You are an email triage assistant. You will be given an ANONYMIZED email
            (names, emails, phone numbers, etc. have been replaced with placeholders like
            [PERSON_1]). Never attempt to guess or reconstruct the real identity behind a
            placeholder — treat placeholders as opaque tokens.

            {$categorySection}

            {$reputationSection}
            {$ragSection}

            Respond ONLY with a JSON object matching this exact shape, no other text:
            {
              "matched_category_id": <int or null>,
              "category_proposal": <{"category": "...", "reasoning": "..."} or null>,
              "summary": "<2-3 sentence summary of the email>",
              "urgency": "<low|medium|high|critical>",
              "confidence": <int 0-100>,
              "suggested_action": "<reply|archive|delete|flag|none>",
              "suggested_reply_draft": <string or null, only if suggested_action is "reply">
            }
            PROMPT;
    }

    protected function userPrompt(TriageRequest $request): string
    {
        return "Sender domain: {$request->senderDomain}\n\n".
               "Subject: {$request->anonymizedSubject}\n\n".
               "Body:\n{$request->anonymizedBody}";
    }

    /**
     * The category-matching instructions, branched on whether any active
     * categories exist yet. With none available the model cannot match, so it is
     * told explicitly that it MUST propose a new one (bootstrapping the taxonomy
     * from the first email); otherwise the usual match-or-propose guidance with
     * the full list is emitted.
     */
    private function formatCategoryInstructions(TriageRequest $request): string
    {
        if (empty($request->availableCategories)) {
            return 'There are no existing categories set up yet, so there is nothing '
                .'to match against. You MUST propose a new category: set '
                .'"matched_category_id" to null and fill "category_proposal" with BOTH '
                .'a concise "category" name AND a one-sentence "reasoning" explaining '
                .'why this category fits the email.';
        }

        $categoryList = collect($request->availableCategories)
            ->map(fn ($c) => "- (id={$c->id}) {$c->name}: {$c->description}")
            ->implode("\n");

        return <<<PROMPT
            Classify the email into ONE of these existing categories if it clearly fits:
            {$categoryList}

            If none of the existing categories fit well, propose a new category by filling
            "category_proposal" with BOTH a concise "category" name AND a one-sentence
            "reasoning" explaining why. If an existing category matches (or you matched one
            above), set "category_proposal" to null instead. Prefer reusing an existing
            category over creating a near-duplicate (e.g. do not propose "Invoices" if
            "Receipt/Invoice" already exists).
            PROMPT;
    }

    private function formatRagExamples(TriageRequest $request): string
    {
        if (empty($request->ragExamples)) {
            return '';
        }

        $examples = collect($request->ragExamples)
            ->map(function ($ex) {
                $correctionNote = $ex->wasUserCorrected ? ' (user-corrected — trust this label strongly)' : '';

                return "- Similar past email \"{$ex->anonymizedSubject}\" was categorized as ".
                       "\"{$ex->categoryName}\", urgency={$ex->urgency}, action={$ex->suggestedAction}{$correctionNote}";
            })
            ->implode("\n");

        return "Here are similar emails you've triaged before, to calibrate consistency:\n{$examples}\n";
    }

    private function formatReputation(TriageRequest $request): string
    {
        $rep = $request->senderReputation;

        if ($rep === null || $rep->isNewSender()) {
            return "This is the first email seen from this sender — no history available.\n";
        }

        return "Sender history: {$rep->emailCount} prior emails, most commonly categorized as ".
               "\"{$rep->mostCommonCategory}\", most common action taken: \"{$rep->mostCommonAction}\".\n";
    }

    /**
     * JSON Schema constraining the LLM's response. Passed to Ollama's `format`
     * field for native structured output, so the model cannot emit malformed
     * JSON, wrong types, or out-of-range enum/numeric values. Enum values are
     * derived from the PHP enums so the two can never drift.
     */
    protected function responseSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'matched_category_id' => ['type' => ['integer', 'null']],
                'category_proposal' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'category' => ['type' => 'string'],
                        'reasoning' => ['type' => 'string'],
                    ],
                    'required' => ['category', 'reasoning'],
                ],
                'summary' => ['type' => 'string'],
                'urgency' => [
                    'type' => 'string',
                    'enum' => array_column(Urgency::cases(), 'value'),
                ],
                'confidence' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
                'suggested_action' => [
                    'type' => 'string',
                    'enum' => array_column(SuggestedAction::cases(), 'value'),
                ],
                'suggested_reply_draft' => ['type' => ['string', 'null']],
            ],
            'required' => [
                'matched_category_id',
                'category_proposal',
                'summary',
                'urgency',
                'confidence',
                'suggested_action',
                'suggested_reply_draft',
            ],
        ];
    }

    /**
     * Parse a raw JSON string from the LLM into the expected structured array.
     * Strips markdown code fences some models add despite instructions.
     */
    protected function parseJsonResponse(string $raw): array
    {
        $cleaned = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($raw)));

        $decoded = json_decode($cleaned, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Failed to parse triage LLM response as JSON: {$raw}");
        }

        return $decoded;
    }

    /**
     * Build the CategoryProposal from the parsed response, enforcing the
     * "both or none" invariant: a proposal exists only when both the category
     * name and reasoning are present and non-empty. Any half-filled, blank, or
     * missing proposal collapses to null so a name and its reason can never
     * appear without each other.
     */
    protected function extractCategoryProposal(array $parsed): ?CategoryProposal
    {
        $proposal = $parsed['category_proposal'] ?? null;

        if (! is_array($proposal)) {
            return null;
        }

        $name = $proposal['category'] ?? null;
        $reasoning = $proposal['reasoning'] ?? null;

        if (! filled($name) || ! filled($reasoning)) {
            return null;
        }

        return new CategoryProposal(name: $name, reasoning: $reasoning);
    }
}
