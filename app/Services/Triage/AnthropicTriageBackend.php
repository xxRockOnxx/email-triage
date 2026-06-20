<?php

namespace App\Services\Triage;

use App\DTOs\TriageRequest;
use App\DTOs\TriageResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicTriageBackend extends AbstractTriageBackend
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function identifier(): string
    {
        return 'anthropic';
    }

    public function triage(TriageRequest $request): TriageResponse
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout(config('triage.timeout', 60))
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => $this->systemPrompt($request),
                'messages' => [
                    ['role' => 'user', 'content' => $this->userPrompt($request)],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Anthropic request failed: {$response->status()} {$response->body()}");
        }

        $text = collect($response->json('content'))
            ->firstWhere('type', 'text')['text'] ?? '';

        $parsed = $this->parseJsonResponse($text);

        return new TriageResponse(
            matchedCategoryId: $parsed['matched_category_id'] ?? null,
            proposedCategoryName: $parsed['proposed_category_name'] ?? null,
            proposedCategoryReasoning: $parsed['proposed_category_reasoning'] ?? null,
            summary: $parsed['summary'] ?? '',
            urgency: $parsed['urgency'] ?? 'low',
            confidence: (int) ($parsed['confidence'] ?? 0),
            suggestedAction: $parsed['suggested_action'] ?? 'none',
            suggestedReplyDraft: $parsed['suggested_reply_draft'] ?? null,
            llmBackend: $this->identifier(),
            llmModel: $this->model,
            rawResponse: $response->json(),
        );
    }
}
