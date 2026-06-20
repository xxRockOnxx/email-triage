<?php

namespace App\Services\Triage;

use App\DTOs\TriageRequest;
use App\DTOs\TriageResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OllamaTriageBackend extends AbstractTriageBackend
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
    ) {}

    public function identifier(): string
    {
        return 'ollama';
    }

    public function triage(TriageRequest $request): TriageResponse
    {
        $response = Http::baseUrl($this->baseUrl)
            ->timeout(config('triage.timeout', 60))
            ->post('/api/chat', [
                'model' => $this->model,
                'stream' => false,
                'format' => $this->responseSchema(),
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt($request)],
                    ['role' => 'user', 'content' => $this->userPrompt($request)],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Ollama request failed: {$response->status()} {$response->body()}");
        }

        $content = $response->json('message.content');
        $parsed = $this->parseJsonResponse($content);

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
