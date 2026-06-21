<?php

namespace App\DTOs;

/**
 * A single, self-contained category proposal: a new category name plus the
 * one-sentence reasoning behind it. Exists (non-null) only as a complete pair
 * — there is no "proposal without a reason" or "reason without a name".
 */
class CategoryProposal
{
    public function __construct(
        public readonly string $name,
        public readonly string $reasoning,
    ) {}
}
