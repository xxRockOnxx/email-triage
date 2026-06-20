<?php

namespace App\Services\Action;

use App\Contracts\MailProviderContract;
use App\Models\ActionLog;
use App\Models\Email;
use App\Models\TriageResult;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Executes user-approved (or auto-filed, high-confidence) actions against
 * the mail provider and logs them for audit + undo. Replies are ALWAYS
 * created as drafts, never auto-sent — the user reviews and sends manually.
 */
class EmailActionService
{
    public function __construct(private readonly MailProviderContract $provider) {}

    public function archive(Email $email, ?TriageResult $triage, string $initiatedBy = 'user'): ActionLog
    {
        $this->provider->archiveMessage($email->gmail_id);

        return $this->log($email, $triage, 'archive', $initiatedBy);
    }

    public function delete(Email $email, ?TriageResult $triage, string $initiatedBy = 'user'): ActionLog
    {
        $this->provider->deleteMessage($email->gmail_id);

        return $this->log($email, $triage, 'delete', $initiatedBy);
    }

    public function flag(Email $email, ?TriageResult $triage, string $initiatedBy = 'user'): ActionLog
    {
        $this->provider->applyLabel($email->gmail_id, 'Flagged');

        return $this->log($email, $triage, 'label_applied', $initiatedBy, ['label' => 'Flagged']);
    }

    public function applyCategoryLabel(Email $email, string $categoryName): ActionLog
    {
        $this->provider->applyLabel($email->gmail_id, $categoryName);

        return $this->log($email, $email->latestTriageResult, 'label_applied', 'auto', ['label' => $categoryName]);
    }

    /**
     * Creates the reply as a Gmail draft only — user must open Gmail (or this
     * app's compose UI) to review and actually send it.
     */
    public function createReplyDraft(Email $email, ?TriageResult $triage, string $body, string $initiatedBy = 'user'): ActionLog
    {
        $draftId = $this->provider->createDraftReply($email->thread_id, $body);

        return $this->log($email, $triage, 'reply_draft_created', $initiatedBy, ['draft_id' => $draftId], $draftId);
    }

    public function undo(ActionLog $actionLog): void
    {
        if (! $actionLog->isUndoable()) {
            throw new RuntimeException("Action {$actionLog->id} of type {$actionLog->action_type} is not undoable.");
        }

        match ($actionLog->action_type) {
            'archive' => $this->provider->applyLabel($actionLog->email->gmail_id, 'INBOX'), // re-add to inbox
            'label_applied' => null, // removing labels via undo intentionally left manual — avoid surprising removals
            default => throw new RuntimeException("Undo not implemented for {$actionLog->action_type}"),
        };

        $actionLog->update(['undone_at' => now()]);
    }

    private function log(
        Email $email,
        ?TriageResult $triage,
        string $actionType,
        string $initiatedBy,
        array $payload = [],
        ?string $gmailActionId = null,
    ): ActionLog {
        return DB::transaction(fn () => ActionLog::create([
            'email_id' => $email->id,
            'triage_result_id' => $triage?->id,
            'action_type' => $actionType,
            'initiated_by' => $initiatedBy,
            'payload' => $payload,
            'gmail_action_id' => $gmailActionId,
            'executed_at' => now(),
        ]));
    }
}
