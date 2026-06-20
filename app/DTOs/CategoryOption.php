<?php

namespace App\DTOs;

class CategoryOption
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $description = null,
    ) {}
}
