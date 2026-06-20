<?php

namespace App\DTOs;

class PiiMapping
{
    public function __construct(
        public readonly string $placeholder,
        public readonly string $entityType,
        public readonly string $originalValue,
        public readonly ?float $detectionScore = null,
    ) {}
}
