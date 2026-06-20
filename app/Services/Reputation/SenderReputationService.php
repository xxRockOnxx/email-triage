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

        $isNew = ! $stat->exists;
        $previousCount = $stat->email_count ?? 0;

        $categoryName = $triage->category?->name ?? $triage->proposed_category_name ?? 'Uncategorized';
        $histogram = $stat->category_histogram ?? [];
        $histogram[$categoryName] = ($histogram[$categoryName] ?? 0) + 1;

        $actionHistogram = $stat->action_histogram ?? [];
        $actionKey = $triage->suggested_action->value;
        $actionHistogram[$actionKey] = ($actionHistogram[$actionKey] ?? 0) + 1;

        $recentActions = $stat->recent_actions ?? [];
        array_unshift($recentActions, [
            'action' => $actionKey,
            'category' => $categoryName,
            'at' => now()->toIso8601String(),
        ]);
        $recentActions = array_slice($recentActions, 0, self::RECENT_ACTIONS_LIMIT);

        $newCount = $previousCount + 1;
        $urgencyWeight = Urgency::from($triage->urgency->value)->weight();

        $stat->fill([
            'sender_domain' => $email->sender_domain,
            'email_count' => $newCount,
            'category_histogram' => $histogram,
            'action_histogram' => $actionHistogram,
            'recent_actions' => $recentActions,
            'avg_urgency_score' => $this->incrementalAverage($stat->avg_urgency_score, $previousCount, $urgencyWeight),
            'avg_confidence' => $this->incrementalAverage($stat->avg_confidence, $previousCount, $triage->confidence),
            'last_seen_at' => now(),
            'stats_updated_at' => now(),
        ]);

        $stat->save();
    }

    private function incrementalAverage(?float $currentAvg, int $previousCount, float $newValue): float
    {
        if ($currentAvg === null || $previousCount === 0) {
            return $newValue;
        }

        return (($currentAvg * $previousCount) + $newValue) / ($previousCount + 1);
    }
}
