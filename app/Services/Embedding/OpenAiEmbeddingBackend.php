<?php

namespace App\Services\Embedding;

use App\Contracts\EmbeddingBackendContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiEmbeddingBackend implements EmbeddingBackendContract
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $dimensions,
    ) {}

    public function embed(string $text): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->model,
                'input' => $text,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("OpenAI embedding request failed: {$response->status()} {$response->body()}");
        }

        return $response->json('data.0.embedding');
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
