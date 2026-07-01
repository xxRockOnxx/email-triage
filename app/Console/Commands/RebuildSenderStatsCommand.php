<?php

namespace App\Console\Commands;

use App\Enums\Urgency;
use App\Models\Email;
use App\Models\SenderStat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off repair for sender_stats rows corrupted by the reputation
 * double-counting bug (fixed in SenderReputationService::reviseTriage()):
 * corrected emails had left both their original and corrected tallies in
 * the histograms forever, and email_count inflated by one per correction.
 * Rebuilds sender_stats from scratch using only the current, final state
 * of triage_results — one tally per email, never double-counted.
 */
class RebuildSenderStatsCommand extends Command
{
    protected $signature = 'sender-stats:rebuild {--dry-run : Show counts without writing}';

    protected $description = 'Truncate and rebuild sender_stats from the current, authoritative state of triage_results.';

    private const RECENT_ACTIONS_LIMIT = 10;

    public function handle(): int
    {
        // withTrashed(): a deleted email's triage result is still legitimate
        // reputation signal — PgVectorStore::findSimilar() treats trashed
        // emails as eligible RAG examples for the same reason, and the
        // original recordTriage()/reviseTriage() calls never filtered on
        // deletion state either.
        $emails = Email::withTrashed()
            ->with(['latestTriageResult.category'])
            ->whereHas('latestTriageResult')
            ->get()
            ->groupBy('sender_email');

        if ($this->option('dry-run')) {
            $this->info("Would rebuild stats for {$emails->count()} senders from ".
                Email::withTrashed()->whereHas('latestTriageResult')->count().' emails.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($emails) {
            SenderStat::truncate();

            foreach ($emails as $senderEmail => $senderEmails) {
                $categoryHistogram = [];
                $actionHistogram = [];
                $recentActions = [];
                $urgencySum = 0;
                $confidenceSum = 0;

                foreach ($senderEmails->sortBy('created_at') as $email) {
                    $triage = $email->latestTriageResult;
                    $category = $triage->category?->name ?? $triage->proposed_category_name ?? 'Uncategorized';
                    $action = $triage->suggested_action->value;

                    $categoryHistogram[$category] = ($categoryHistogram[$category] ?? 0) + 1;
                    $actionHistogram[$action] = ($actionHistogram[$action] ?? 0) + 1;
                    $urgencySum += Urgency::from($triage->urgency->value)->weight();
                    $confidenceSum += $triage->confidence;

                    array_unshift($recentActions, [
                        'action' => $action,
                        'category' => $category,
                        'at' => $triage->updated_at->toIso8601String(),
                    ]);

                    // Repopulate so future corrections have a correct baseline to reverse.
                    $triage->update(['reputation_snapshot' => [
                        'action' => $action,
                        'category_name' => $category,
                        'urgency_weight' => Urgency::from($triage->urgency->value)->weight(),
                        'confidence' => $triage->confidence,
                    ]]);
                }

                $count = $senderEmails->count();

                SenderStat::create([
                    'sender_email' => $senderEmail,
                    'sender_domain' => $senderEmails->first()->sender_domain,
                    'email_count' => $count,
                    'category_histogram' => $categoryHistogram,
                    'action_histogram' => $actionHistogram,
                    'avg_urgency_score' => $urgencySum / $count,
                    'avg_confidence' => $confidenceSum / $count,
                    'recent_actions' => array_slice($recentActions, 0, self::RECENT_ACTIONS_LIMIT),
                    'last_seen_at' => $senderEmails->max('created_at'),
                    'stats_updated_at' => now(),
                ]);
            }
        });

        $this->info("Rebuilt sender_stats for {$emails->count()} senders.");

        return self::SUCCESS;
    }
}
