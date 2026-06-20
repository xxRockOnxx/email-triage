<?php

namespace App\Services\Embedding;

use App\Contracts\EmbeddingBackendContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OllamaEmbeddingBackend implements EmbeddingBackendContract
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $dimensions,
    ) {}

    public function embed(string $text): array
    {
        $response = Http::baseUrl($this->baseUrl)
            ->timeout(config('embedding.timeout', 30))
            ->post('/api/embeddings', [
                'model' => $this->model,
                'prompt' => $text,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Ollama embedding request failed: {$response->status()} {$response->body()}");
        }

        return $response->json('embedding');
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }
}
