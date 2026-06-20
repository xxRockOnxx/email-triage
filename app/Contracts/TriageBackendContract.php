<?php

namespace App\Contracts;

use App\DTOs\TriageRequest;
use App\DTOs\TriageResponse;

/**
 * Pluggable LLM backend for triage. Implementations: Ollama (local), Anthropic,
 * OpenAI. Selected at runtime via config('triage.backend'). Every implementation
 * receives ONLY anonymized text — never raw PII.
 */
interface TriageBackendContract
{
    public function triage(TriageRequest $request): TriageResponse;

    /**
     * Backend identifier used for storage/auditing, e.g. "ollama", "anthropic", "openai".
     */
    public function identifier(): string;
}
