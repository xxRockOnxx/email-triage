<?php

namespace App\Providers;

use App\Contracts\AnonymizerContract;
use App\Contracts\EmbeddingBackendContract;
use App\Contracts\MailProviderContract;
use App\Contracts\TriageBackendContract;
use App\Contracts\VectorStoreContract;
use App\Services\Anonymization\PresidioAnonymizer;
use App\Services\Embedding\OllamaEmbeddingBackend;
use App\Services\Embedding\OpenAiEmbeddingBackend;
use App\Services\Imap\ImapGmailProvider;
use App\Services\Triage\AnthropicTriageBackend;
use App\Services\Triage\OllamaTriageBackend;
use App\Services\Triage\OpenAiTriageBackend;
use App\Services\Vector\PgVectorStore;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AnonymizerContract::class, PresidioAnonymizer::class);
        $this->app->bind(VectorStoreContract::class, PgVectorStore::class);

        $this->app->bind(MailProviderContract::class, function () {
            return new ImapGmailProvider(
                host: config('gmail.imap_host'),
                imapPort: config('gmail.imap_port'),
                smtpPort: config('gmail.smtp_port'),
                username: config('gmail.account_email'),
                appPassword: config('gmail.app_password'),
            );
        });

        $this->app->bind(TriageBackendContract::class, function () {
            return match (config('triage.backend')) {
                'ollama' => new OllamaTriageBackend(
                    config('triage.ollama.base_url'),
                    config('triage.ollama.model'),
                ),
                'anthropic' => new AnthropicTriageBackend(
                    config('triage.anthropic.api_key'),
                    config('triage.anthropic.model'),
                ),
                'openai' => new OpenAiTriageBackend(
                    config('triage.openai.api_key'),
                    config('triage.openai.model'),
                ),
                default => throw new RuntimeException('Unknown triage.backend: '.config('triage.backend')),
            };
        });

        $this->app->bind(EmbeddingBackendContract::class, function () {
            return match (config('embedding.backend')) {
                'ollama' => new OllamaEmbeddingBackend(
                    config('embedding.ollama.base_url'),
                    config('embedding.ollama.model'),
                    config('embedding.dimensions'),
                ),
                'openai' => new OpenAiEmbeddingBackend(
                    config('embedding.openai.api_key'),
                    config('embedding.openai.model'),
                    config('embedding.dimensions'),
                ),
                default => throw new RuntimeException('Unknown embedding.backend: '.config('embedding.backend')),
            };
        });
    }
}
