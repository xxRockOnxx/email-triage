<?php

namespace App\Http\Controllers;

use App\DTOs\PiiMapping;
use App\Enums\Urgency;
use App\Models\Category;
use App\Models\Email;
use App\Services\Anonymization\PresidioAnonymizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EmailController extends Controller
{
    /**
     * Main triage queue. Supports filtering by status (needs_review/auto_filed),
     * category, and urgency via query params.
     */
    public function index(Request $request, PresidioAnonymizer $anonymizer): Response
    {
        $query = Email::query()
            ->with(['latestTriageResult.category', 'piiMappings'])
            ->leftJoinSub(
                $this->latestTriageResultSubquery(),
                'latest_triage',
                'latest_triage.email_id',
                '=',
                'emails.id'
            )
            ->select('emails.*');

        if ($status = $request->query('status')) {
            // 'failed' is a pipeline status (no triage result exists), so it
            // filters on the email rather than latestTriageResult.
            if ($status === 'failed') {
                $query->failed();
            } else {
                $query->whereHas('latestTriageResult', fn ($q) => $q->where('status', $status));
            }
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

        $this->applySorting($query, $request);

        $emails = $query->paginate(25)->withQueryString();

        // De-anonymize each LLM summary in place so the queue shows real
        // names/details, matching the detail page. The LLM only ever saw
        // anonymized text, so its summary otherwise holds [PERSON_1]-style
        // placeholders.
        foreach ($emails as $email) {
            $triage = $email->latestTriageResult;
            if ($triage?->summary) {
                $triage->summary = $this->deanonymizedSummary($email, $anonymizer);
            }
        }

        return Inertia::render('Emails/Index', [
            'emails' => $emails,
            'filters' => (object) $request->only(['status', 'category_id', 'urgency', 'q', 'sort']),
        ]);
    }

    /**
     * Sorts by both urgency severity and received_at. Default behavior
     * (no ?sort param) is most-urgent-first, then most-recent-first within
     * the same urgency — this is the order a triage queue is actually meant
     * to be worked in. ?sort=recent flips the primary key to received_at,
     * with urgency as the tiebreaker, for a chronological inbox view.
     *
     * urgency is a string enum (low/medium/high/critical) whose alphabetical
     * order doesn't match severity order, so it's mapped via CASE to the
     * same weight scale as Urgency::weight() before sorting.
     */
    private function applySorting($query, Request $request): void
    {
        $urgencyWeightCase = '
            CASE latest_triage.urgency
                WHEN \''.Urgency::Critical->value.'\' THEN 4
                WHEN \''.Urgency::High->value.'\' THEN 3
                WHEN \''.Urgency::Medium->value.'\' THEN 2
                WHEN \''.Urgency::Low->value.'\' THEN 1
                ELSE 0
            END
        ';

        if ($request->query('sort') === 'recent') {
            $query->orderByDesc('emails.received_at')
                ->orderByRaw("{$urgencyWeightCase} DESC");
        } else {
            $query->orderByRaw("{$urgencyWeightCase} DESC")
                ->orderByDesc('emails.received_at');
        }
    }

    /**
     * Mirrors Email::latestTriageResult()'s ->latestOfMany() semantics
     * (latest = highest id per email_id) as a joinable subquery, since
     * Eloquent's hasOne/latestOfMany can't be used directly inside an
     * ORDER BY on the parent query. Grouping by email_id alone (not
     * urgency) is what guarantees exactly one row per email — the single
     * most-recent triage_result, whatever its urgency happens to be.
     */
    private function latestTriageResultSubquery()
    {
        $latestIds = DB::table('triage_results')
            ->select('email_id', DB::raw('MAX(id) as latest_id'))
            ->groupBy('email_id');

        return DB::table('triage_results')
            ->joinSub($latestIds, 'latest_ids', function ($join) {
                $join->on('triage_results.id', '=', 'latest_ids.latest_id');
            })
            ->select('triage_results.email_id', 'triage_results.urgency');
    }

    public function show(Email $email, PresidioAnonymizer $anonymizer): Response
    {
        $email->load(['latestTriageResult.category', 'piiMappings', 'actionsLog', 'pipelineLogs']);

        // Active categories for the correction panel's category dropdown.
        $categories = Category::active()->orderBy('name')->get(['id', 'name']);

        // De-anonymize the LLM's summary for display so the user sees real
        // names/details, even though the LLM itself never saw them.
        $deanonymizedSummary = $this->deanonymizedSummary($email, $anonymizer);

        return Inertia::render('Emails/Show', [
            'email' => $email,
            'deanonymized_summary' => $deanonymizedSummary,
            'categories' => $categories,
        ]);
    }

    /**
     * Reverses anonymization on an email's latest triage summary so the UI
     * shows real names/details, even though the LLM only saw anonymized text.
     * Returns null when there is no summary yet (awaiting triage).
     */
    private function deanonymizedSummary(Email $email, PresidioAnonymizer $anonymizer): ?string
    {
        $summary = $email->latestTriageResult?->summary;

        if (! $summary) {
            return null;
        }

        $mappings = $email->piiMappings->map(fn ($m) => new PiiMapping(
            placeholder: $m->placeholder,
            entityType: $m->entity_type,
            originalValue: $m->original_value_enc, // decrypted transparently via the model cast
            detectionScore: $m->detection_score,
        ))->all();

        return $anonymizer->deanonymize($summary, $mappings);
    }
}
