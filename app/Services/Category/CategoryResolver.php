<?php

namespace App\Services\Category;

use App\DTOs\CategoryOption;
use App\DTOs\TriageResponse;
use App\Enums\CategorySource;
use App\Enums\TriageStatus;
use App\Models\Category;
use Illuminate\Support\Collection;

/**
 * Resolves a TriageResponse's category outcome: either an existing category,
 * or a pending proposal. New categories are NEVER auto-created — they land
 * in 'pending_review' to prevent category sprawl (e.g. "Invoice" vs
 * "Invoices" vs "Billing"). A human accepts/merges/rejects via the UI.
 */
class CategoryResolver
{
    /**
     * @return Collection<int, CategoryOption>
     */
    public function activeOptionsForPrompt(): Collection
    {
        return Category::active()
            ->orderBy('name')
            ->get()
            ->map(fn (Category $c) => new CategoryOption($c->id, $c->name, $c->description));
    }

    /**
     * Persist the category side-effects of a triage response: if the LLM
     * proposed a new category, create it as pending_review (idempotent on name).
     */
    public function resolve(TriageResponse $response): ?Category
    {
        if ($response->matchedCategoryId !== null) {
            return Category::find($response->matchedCategoryId);
        }

        if ($response->proposedCategoryName === null) {
            return null;
        }

        // Idempotent: if this exact name was already proposed (e.g. by an earlier
        // email this batch), reuse it rather than creating duplicates.
        return Category::firstOrCreate(
            ['name' => $response->proposedCategoryName],
            [
                'description' => $response->proposedCategoryReasoning,
                'source' => CategorySource::Llm,
                'status' => 'pending_review',
            ]
        );
    }

    /**
     * Determine auto_filed vs needs_review based on confidence vs the
     * category's effective threshold. Proposed (pending_review) categories
     * always route to needs_review regardless of confidence — a brand new
     * category should always get a human look before it's trusted.
     */
    public function routeStatus(TriageResponse $response, ?Category $category): TriageStatus
    {
        if ($category === null || $category->status === 'pending_review') {
            return TriageStatus::NeedsReview;
        }

        return $response->confidence >= $category->effectiveConfidenceThreshold()
            ? TriageStatus::AutoFiled
            : TriageStatus::NeedsReview;
    }
}
