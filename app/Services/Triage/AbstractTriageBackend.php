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
        $actionSection = $this->formatActionInstructions();
        $ragSection = $this->formatRagExamples($request);
        $reputationSection = $this->formatReputation($request);

        return <<<PROMPT
            You are an email triage assistant. You will be given an ANONYMIZED email
            (names, emails, phone numbers, etc. have been replaced with placeholders like
            [PERSON_1]). Never attempt to guess or reconstruct the real identity behind a
            placeholder — treat placeholders as opaque tokens.

            {$categorySection}

            {$actionSection}

            {$reputationSection}
            {$ragSection}

            Respond ONLY with a JSON object matching this exact shape, no other text:
            {
              "triage_reasoning": "<1-2 sentences explaining WHY you chose this category/urgency/action, referencing sender history or past examples if used>",
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

    /**
     * Criteria for "suggested_action" and "confidence". Framed as defaults
     * rather than fixed rules, and explicitly subordinate to the RAG
     * examples section below (user corrections are ground truth) — without
     * that precedence stated outright, a static rule and a conflicting past
     * correction would compete with no way for the model to arbitrate.
     */
    private function formatActionInstructions(): string
    {
        return <<<PROMPT
            Choose "suggested_action" using these as DEFAULT guidance, not fixed rules:
            - "reply": addressed to the user and expects a response, answer, decision,
              or action from them personally (a direct question, request for input,
              meeting proposal). Also draft "suggested_reply_draft" in this case.
            - "flag": important and needs the user's personal attention or a decision
              soon, but doesn't itself need a reply.
            - "delete": obviously spam, phishing, or worthless with no informational
              value to anyone.
            - "archive": informational or resolved, worth keeping for reference but
              needing no action. Default here only when reply/flag/delete clearly
              don't apply — not as a catch-all for anything you're unsure about.
            - "none": you genuinely cannot determine an action from the content (rare).

            IMPORTANT — precedence: the above are fallback defaults for when you have
            no other signal. If a similar past email below (especially one marked USER
            CORRECTION) shows this sender, category, or type of content being handled
            differently, follow that precedent instead of the default — it reflects
            this user's actual preference and should win.

            Set "confidence" (0-100) honestly per email based on how clearly the content
            supports both the category and action — vary it, don't default to a fixed
            number:
            - 90-100: category and action both clearly supported by the content.
            - 70-89: one is clear, the other is a reasonable judgment call.
            - 40-69: ambiguous, very short, or boilerplate content.
            - Below 40: almost no signal to go on.
            PROMPT;
    }

    private function formatRagExamples(TriageRequest $request): string
    {
        if (empty($request->ragExamples)) {
            return '';
        }

        $examples = collect($request->ragExamples)
            ->map(function ($ex) {
                if ($ex->wasUserCorrected) {
                    return "- Similar past email \"{$ex->anonymizedSubject}\" (USER CORRECTION):\n" .
                           "  Initially: category=\"{$ex->originalLlmCategory}\", urgency={$ex->originalLlmUrgency}, action={$ex->originalLlmAction}\n" .
                           "  Corrected to: category=\"{$ex->categoryName}\", urgency={$ex->urgency}, action={$ex->suggestedAction}";
                }

                return "- Similar past email \"{$ex->anonymizedSubject}\" was categorized as " .
                       "\"{$ex->categoryName}\", urgency={$ex->urgency}, action={$ex->suggestedAction}";
            })
            ->implode("\n");

        return "Here are similar past emails to calibrate consistency. " .
               "User-corrected examples are ground truth — weight them more heavily than uncorrected ones:\n" .
               "{$examples}\n";
    }

    private function formatReputation(TriageRequest $request): string
    {
        $rep = $request->senderReputation;

        if ($rep === null || $rep->isNewSender()) {
            return "This is the first email seen from this sender — no history available.\n";
        }

        return "Background only — sender history: {$rep->emailCount} prior emails, most commonly ".
               "categorized as \"{$rep->mostCommonCategory}\", most common action taken: ".
               "\"{$rep->mostCommonAction}\". Do NOT default to this action just because it's common ".
               "for this sender — judge THIS email's own content first, and only use sender history ".
               "as a tiebreaker when the content itself is ambiguous.\n";
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
                'triage_reasoning' => ['type' => 'string'],
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
                'triage_reasoning',
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
