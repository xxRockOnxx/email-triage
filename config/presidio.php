<?php

return [
    // Official Presidio docker images, run locally e.g. via docker-compose:
    //   presidio-analyzer:   mcr.microsoft.com/presidio-analyzer
    //   presidio-anonymizer: mcr.microsoft.com/presidio-anonymizer (not used directly —
    //     see PresidioAnonymizer, which builds placeholders itself for reversibility)
    'analyzer_url' => env('PRESIDIO_ANALYZER_URL', 'http://localhost:5001'),
    'anonymizer_url' => env('PRESIDIO_ANONYMIZER_URL', 'http://localhost:5002'),

    'timeout' => env('PRESIDIO_TIMEOUT', 15),

    // Null = use Presidio's full default recognizer set. Restrict to specific
    // entity types here if you want to tune precision, e.g.:
    // ['PERSON', 'EMAIL_ADDRESS', 'PHONE_NUMBER', 'LOCATION', 'ORGANIZATION', 'CREDIT_CARD']
    'entities' => env('PRESIDIO_ENTITIES') ? explode(',', env('PRESIDIO_ENTITIES')) : null,

    // Minimum detection confidence (0-1) for an entity to be anonymized.
    // Lower = more aggressive anonymization, more false positives.
    'score_threshold' => (float) env('PRESIDIO_SCORE_THRESHOLD', 0.4),
];
