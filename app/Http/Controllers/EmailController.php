<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Email;
use App\Services\Anonymization\PresidioAnonymizer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    /**
     * Main triage queue. Supports filtering by status (needs_review/auto_filed),
     * category, and urgency via query params.
     */
    public function index(Request $request): Response
    {
        $query = Email::query()
            ->with(['latestTriageResult.category'])
            ->orderByDesc('received_at');

        if ($status = $request->query('status')) {
            $query->whereHas('latestTriageResult', fn ($q) => $q->where('status', $status));
        }

        if ($categoryId = $request->query('category_id')) {
            $query->whereHas('latestTriageResult', fn ($q) => $q->where('category_id', $categoryId));
        }

        if ($urgency = $request->query('urgency')) {
            $query->whereHas('latestTriageResult', fn ($q) => $q->where('urgency', $urgency));
        }

        if ($search = $request->query('q')) {
            // Uses the generated search_vector column (GIN-indexed) added in
            // the emails migration — plainto_tsquery handles normal user
            // input (no need to teach users tsquery syntax).
            $query->whereRaw('search_vector @@ plainto_tsquery(\'english\', ?)', [$search]);
        }

        $emails = $query->paginate(25)->withQueryString();

        return Inertia::render('Emails/Index', [
            'emails' => $emails,
            'filters' => $request->only(['status', 'category_id', 'urgency', 'q']),
        ]);
    }

    public function show(Email $email, PresidioAnonymizer $anonymizer): Response
    {
        $email->load(['latestTriageResult.category', 'piiMappings', 'actionsLog']);

        // Active categories for the correction panel's category dropdown.
        $categories = Category::active()->orderBy('name')->get(['id', 'name']);

        // De-anonymize the LLM's summary for display so the user sees real
        // names/details, even though the LLM itself never saw them.
        $summary = $email->latestTriageResult?->summary;

        $mappingDtos = $email->piiMappings->map(fn ($m) => new \App\DTOs\PiiMapping(
            placeholder: $m->placeholder,
            entityType: $m->entity_type,
            originalValue: $m->original_value_enc, // decrypted transparently via the model cast
            detectionScore: $m->detection_score,
        ))->all();

        $deanonymizedSummary = $summary
            ? $anonymizer->deanonymize($summary, $mappingDtos)
            : null;

        return Inertia::render('Emails/Show', [
            'email' => $email,
            'deanonymized_summary' => $deanonymizedSummary,
            'categories' => $categories,
        ]);
    }
}
