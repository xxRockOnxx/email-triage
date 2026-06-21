<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\Email;
use App\Services\Action\EmailActionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailActionController extends Controller
{
    public function __construct(private readonly EmailActionService $actionService) {}

    public function archive(Email $email): RedirectResponse
    {
        $this->actionService->archive($email, $email->latestTriageResult);

        return back()->with('success', 'Email archived.');
    }

    public function delete(Email $email): RedirectResponse
    {
        $this->actionService->delete($email, $email->latestTriageResult);

        // Not back() — if this was triggered from the email's own show page,
        // that route will 404 now that the email is soft-deleted (implicit
        // route-model binding excludes trashed models by default).
        return redirect()->route('emails.index')->with('success', 'Email moved to trash.');
    }

    public function flag(Email $email): RedirectResponse
    {
        $this->actionService->flag($email, $email->latestTriageResult);

        return back()->with('success', 'Email flagged.');
    }

    public function createReplyDraft(Request $request, Email $email): RedirectResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        $this->actionService->createReplyDraft($email, $email->latestTriageResult, $validated['body']);

        return back()->with('success', 'Draft created in Gmail — review and send when ready.');
    }

    public function undo(ActionLog $actionLog): RedirectResponse
    {
        $this->actionService->undo($actionLog);

        return back()->with('success', 'Action undone.');
    }
}
