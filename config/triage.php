<?php

return [
    // Which TriageBackendContract implementation to bind. One of: ollama, anthropic, openai.
    // Bound in AppServiceProvider based on this value.
    'backend' => env('TRIAGE_BACKEND', 'ollama'),

    'timeout' => env('TRIAGE_TIMEOUT', 60),

    // Global fallback confidence threshold (0-100) for auto-filing when a
    // category has no per-category override (Category::confidence_threshold).
    'default_confidence_threshold' => env('TRIAGE_DEFAULT_CONFIDENCE_THRESHOLD', 75),

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_TRIAGE_MODEL', 'phi3.5'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_TRIAGE_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_TRIAGE_MODEL', 'gpt-4o-mini'),
    ],
];
