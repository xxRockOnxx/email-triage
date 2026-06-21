<?php

namespace App\Services\Triage;

use App\DTOs\TriageRequest;
use App\DTOs\TriageResponse;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiTriageBackend extends AbstractTriageBackend
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
    ) {}

    public function identifier(): string
    {
        return 'openai';
    }

    public function triage(TriageRequest $request): TriageResponse
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(config('triage.timeout', 60))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($request)],
                    ['role' => 'user', 'content' => $this->userPrompt($request)],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("OpenAI request failed: {$response->status()} {$response->body()}");
        }

        $content = $response->json('choices.0.message.content');
        $parsed = $this->parseJsonResponse($content);

        return new TriageResponse(
            matchedCategoryId: $parsed['matched_category_id'] ?? null,
            categoryProposal: $this->extractCategoryProposal($parsed),
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
