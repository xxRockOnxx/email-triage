<?php

namespace App\Http\Controllers;

use App\Jobs\PollGmailJob;
use App\Models\Email;
use App\Models\GmailSyncState;
use App\Models\TriageResult;
use Cron\CronExpression;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'needs_review_count' => TriageResult::where('status', 'needs_review')->count(),
                'auto_filed_count' => TriageResult::where('status', 'auto_filed')->count(),
                'total_emails' => Email::count(),
                'pending_categories' => \App\Models\Category::where('status', 'pending_review')->count(),
                'critical_urgency_count' => TriageResult::where('urgency', 'critical')
                    ->where('status', '!=', 'corrected')
                    ->whereHas('email', fn ($q) => $q->whereDoesntHave('actionsLog'))
                    ->count(),
            ],
            'sync_state' => GmailSyncState::first(),
            'next_poll_at' => $this->getNextPollTime()->format('c'),
            'recent_needs_review' => Email::with('latestTriageResult.category')
                ->whereHas('latestTriageResult', fn ($q) => $q->where('status', 'needs_review'))
                ->orderByDesc('received_at')
                ->limit(10)
                ->get(),
        ]);
    }

    private function getNextPollTime(): \DateTime
    {
        $cronExpression = config('gmail.poll_cron', '*/5 * * * *');
        $cron = new CronExpression($cronExpression);
        return $cron->getNextRunDate();
    }

    public function pollNow(): \Illuminate\Http\RedirectResponse
    {
        PollGmailJob::dispatch(config('gmail.account_email'));

        return redirect()->route('dashboard');
    }
}
