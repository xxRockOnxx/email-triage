<?php

namespace App\Http\Controllers;

use App\Enums\TriageStatus;
use App\Models\TriageResult;
use App\Services\Reputation\SenderReputationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TriageResultController extends Controller
{
    /**
     * User approves a triage result as-is (no changes) — typically used from
     * the needs_review queue to confirm the LLM got it right.
     */
    public function approve(TriageResult $triageResult): RedirectResponse
    {
        $triageResult->update(['status' => TriageStatus::AutoFiled]);

        return back()->with('success', 'Triage approved.');
    }

    /**
     * User corrects a triage result: overwrites the live category/urgency/action
     * labels on the TriageResult and marks it corrected, then folds the new
     * labels into reputation. The immutable llm_* snapshot columns (the LLM's
     * original prediction, captured at triage) are left untouched, so corrected
     * results can later serve as negative-example RAG context.
     */
    public function correct(Request $request, TriageResult $triageResult, SenderReputationService $reputationService): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'urgency' => ['nullable', 'in:low,medium,high,critical'],
            'suggested_action' => ['nullable', 'in:reply,archive,delete,flag,none'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $triageResult->update(array_filter([
            'category_id' => $validated['category_id'] ?? null,
            'urgency' => $validated['urgency'] ?? null,
            'suggested_action' => $validated['suggested_action'] ?? null,
        ], fn ($v) => $v !== null) + ['status' => TriageStatus::Corrected]);

        $reputationService->reviseTriage($triageResult->email, $triageResult->fresh());

        return back()->with('success', 'Correction saved — this will improve future triage.');
    }
}
