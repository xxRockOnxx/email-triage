<?php

namespace App\Http\Controllers;

use App\Enums\TriageStatus;
use App\Jobs\ReembedAfterCorrectionJob;
use App\Models\Correction;
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
     * User corrects a triage result. Writes a Correction record (the core
     * training signal), updates the TriageResult in place, re-embeds the
     * email so it becomes a high-trust RAG example, and folds the correction
     * into sender reputation.
     */
    public function correct(Request $request, TriageResult $triageResult, SenderReputationService $reputationService): RedirectResponse
    {
        $validated = $request->validate([
            'category_id' => ['nullable', 'exists:categories,id'],
            'urgency' => ['nullable', 'in:low,medium,high,critical'],
            'suggested_action' => ['nullable', 'in:reply,archive,delete,flag,none'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        Correction::create([
            'triage_result_id' => $triageResult->id,
            'old_category_id' => $triageResult->category_id,
            'new_category_id' => $validated['category_id'] ?? $triageResult->category_id,
            'old_urgency' => $triageResult->urgency->value,
            'new_urgency' => $validated['urgency'] ?? $triageResult->urgency->value,
            'old_suggested_action' => $triageResult->suggested_action->value,
            'new_suggested_action' => $validated['suggested_action'] ?? $triageResult->suggested_action->value,
            'note' => $validated['note'] ?? null,
            'corrected_at' => now(),
        ]);

        $triageResult->update(array_filter([
            'category_id' => $validated['category_id'] ?? null,
            'urgency' => $validated['urgency'] ?? null,
            'suggested_action' => $validated['suggested_action'] ?? null,
            'status' => TriageStatus::Corrected,
        ], fn ($v) => $v !== null) + ['status' => TriageStatus::Corrected]);

        $reputationService->recordTriage($triageResult->email, $triageResult->fresh());

        ReembedAfterCorrectionJob::dispatch($triageResult->email_id);

        return back()->with('success', 'Correction saved — this will improve future triage.');
    }
}
