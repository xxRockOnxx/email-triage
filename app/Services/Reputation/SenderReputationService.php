<?php

namespace App\Services\Reputation;

use App\DTOs\SenderReputationSummary;
use App\Enums\Urgency;
use App\Models\Email;
use App\Models\SenderStat;
use App\Models\TriageResult;

/**
 * Maintains the sender_stats rolling aggregate table. Updated incrementally
 * after each triage (cheap) rather than recomputed from scratch each time.
 */
class SenderReputationService
{
    private const RECENT_ACTIONS_LIMIT = 10;

    public function summaryFor(string $senderEmail): ?SenderReputationSummary
    {
        $stat = SenderStat::where('sender_email', $senderEmail)->first();

        if (! $stat) {
            return new SenderReputationSummary(
                senderEmail: $senderEmail,
                emailCount: 0,
                mostCommonCategory: null,
                avgUrgencyScore: null,
                avgConfidence: null,
                mostCommonAction: null,
            );
        }

        return new SenderReputationSummary(
            senderEmail: $stat->sender_email,
            emailCount: $stat->email_count,
            mostCommonCategory: $stat->mostCommonCategory(),
            avgUrgencyScore: $stat->avg_urgency_score,
            avgConfidence: $stat->avg_confidence,
            mostCommonAction: $stat->mostCommonAction(),
        );
    }

    /**
     * Incrementally fold a new triage result into the sender's rolling stats.
     * Call this right after a TriageResult is created.
     */
    public function recordTriage(Email $email, TriageResult $triage): void
    {
        $stat = SenderStat::firstOrNew(['sender_email' => $email->sender_email]);

        $previousCount = $stat->email_count ?? 0;
        $snapshot = $this->snapshotFrom($triage);

        $categoryHistogram = $stat->category_histogram ?? [];
        $categoryHistogram[$snapshot['category_name']] = ($categoryHistogram[$snapshot['category_name']] ?? 0) + 1;

        $actionHistogram = $stat->action_histogram ?? [];
        $actionHistogram[$snapshot['action']] = ($actionHistogram[$snapshot['action']] ?? 0) + 1;

        $recentActions = $stat->recent_actions ?? [];
        array_unshift($recentActions, [
            'action' => $snapshot['action'],
            'category' => $snapshot['category_name'],
            'at' => now()->toIso8601String(),
        ]);
        $recentActions = array_slice($recentActions, 0, self::RECENT_ACTIONS_LIMIT);

        $stat->fill([
            'sender_domain' => $email->sender_domain,
            'email_count' => $previousCount + 1,
            'category_histogram' => $categoryHistogram,
            'action_histogram' => $actionHistogram,
            'recent_actions' => $recentActions,
            'avg_urgency_score' => $this->incrementalAverage($stat->avg_urgency_score, $previousCount, $snapshot['urgency_weight']),
            'avg_confidence' => $this->incrementalAverage($stat->avg_confidence, $previousCount, $snapshot['confidence']),
            'last_seen_at' => now(),
            'stats_updated_at' => now(),
        ]);

        $stat->save();

        $triage->update(['reputation_snapshot' => $snapshot]);
    }

    /**
     * Reverse a triage result's previously-folded contribution to sender_stats
     * and fold in its corrected values instead. Call this from the correction
     * flow in place of recordTriage(). Does NOT touch email_count — it's the
     * same email, not a new observation.
     *
     * Safe to call on repeat corrections: it always reverses reputation_snapshot
     * (what THIS row last folded in), not the immutable llm_* columns — those
     * only capture the LLM's original guess, which would be the wrong thing to
     * reverse against after a second correction.
     */
    public function reviseTriage(Email $email, TriageResult $triage): void
    {
        $stat = SenderStat::where('sender_email', $email->sender_email)->first();

        $old = $triage->reputation_snapshot ?? [
            'action' => $triage->llm_suggested_action->value,
            'category_name' => $triage->llmCategory?->name ?? $triage->proposed_category_name ?? 'Uncategorized',
            'urgency_weight' => Urgency::from($triage->llm_urgency->value)->weight(),
            'confidence' => $triage->confidence,
        ];
        $new = $this->snapshotFrom($triage);

        $n = max($stat->email_count, 1);

        $recentActions = $stat->recent_actions ?? [];
        array_unshift($recentActions, [
            'action' => $new['action'],
            'category' => $new['category_name'],
            'at' => now()->toIso8601String(),
        ]);
        $recentActions = array_slice($recentActions, 0, self::RECENT_ACTIONS_LIMIT);

        $stat->fill([
            'category_histogram' => $this->shiftHistogram($stat->category_histogram ?? [], $old['category_name'], $new['category_name']),
            'action_histogram' => $this->shiftHistogram($stat->action_histogram ?? [], $old['action'], $new['action']),
            'recent_actions' => $recentActions,
            'avg_urgency_score' => $stat->avg_urgency_score + ($new['urgency_weight'] - $old['urgency_weight']) / $n,
            'avg_confidence' => $stat->avg_confidence + ($new['confidence'] - $old['confidence']) / $n,
            'last_seen_at' => now(),
            'stats_updated_at' => now(),
        ]);

        $stat->save();

        $triage->update(['reputation_snapshot' => $new]);
    }

    /**
     * Move one tally from $oldKey to $newKey, dropping $oldKey once it hits
     * zero so mostCommonAction()/mostCommonCategory() never see a stale key.
     */
    private function shiftHistogram(array $histogram, string $oldKey, string $newKey): array
    {
        if ($oldKey === $newKey) {
            return $histogram;
        }

        if (isset($histogram[$oldKey])) {
            $histogram[$oldKey]--;
            if ($histogram[$oldKey] <= 0) {
                unset($histogram[$oldKey]);
            }
        }

        $histogram[$newKey] = ($histogram[$newKey] ?? 0) + 1;

        return $histogram;
    }

    private function snapshotFrom(TriageResult $triage): array
    {
        return [
            'action' => $triage->suggested_action->value,
            'category_name' => $triage->category?->name ?? $triage->proposed_category_name ?? 'Uncategorized',
            'urgency_weight' => Urgency::from($triage->urgency->value)->weight(),
            'confidence' => $triage->confidence,
        ];
    }

    private function incrementalAverage(?float $currentAvg, int $previousCount, float $newValue): float
    {
        if ($currentAvg === null || $previousCount === 0) {
            return $newValue;
        }

        return (($currentAvg * $previousCount) + $newValue) / ($previousCount + 1);
    }
}
