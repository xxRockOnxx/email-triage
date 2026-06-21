<?php

namespace App\Jobs;

use App\Contracts\MailProviderContract;
use App\Models\Email;
use App\Models\GmailSyncState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pipeline stage 1: poll Gmail for new messages since the last cursor,
 * persist raw (still-PII-containing) emails, then dispatch the anonymize
 * job per email. Scheduled to run on the configured interval.
 *
 * ShouldBeUnique prevents overlapping polls if a previous run is still
 * in flight when the next scheduled tick fires.
 */
class PollGmailJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300; // 5 minutes
    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(private readonly string $gmailAccountEmail) {}

    public function uniqueId(): string
    {
        return $this->gmailAccountEmail;
    }

    public function handle(MailProviderContract $provider): void
    {
        $syncState = GmailSyncState::firstOrCreate(
            ['gmail_account_email' => $this->gmailAccountEmail],
            ['status' => 'idle']
        );

        $syncState->update(['status' => 'polling']);

        try {
            $result = $provider->fetchNewMessages($syncState->sync_cursor);

            $newEmailIds = [];

            foreach ($result['messages'] as $inboundEmail) {
                $email = $this->persistRawEmail($inboundEmail);

                if ($email->wasRecentlyCreated) {
                    $newEmailIds[] = $email->id;
                }
            }

            $syncState->update([
                'sync_cursor' => $result['next_cursor'],
                'last_polled_at' => now(),
                'status' => 'idle',
                'last_error' => null,
            ]);

            foreach ($newEmailIds as $emailId) {
                AnonymizeEmailJob::dispatch($emailId);
            }

            Log::info('Gmail poll complete', [
                'account' => $this->gmailAccountEmail,
                'new_emails' => count($newEmailIds),
            ]);
        } catch (\Throwable $e) {
            $syncState->update([
                'status' => 'error',
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function persistRawEmail(\App\DTOs\InboundEmail $inbound): Email
    {
        return DB::transaction(function () use ($inbound) {
            return Email::firstOrCreate(
                ['gmail_id' => $inbound->providerMessageId],
                [
                    'thread_id' => $inbound->providerThreadId,
                    'sender_email' => $inbound->senderEmail,
                    'sender_name' => $inbound->senderName,
                    'sender_domain' => $inbound->senderDomain(),
                    'subject_enc' => $inbound->subject,
                    'body_enc' => $inbound->bodyText,
                    'body_html_enc' => $inbound->bodyHtml,
                    'gmail_labels' => $inbound->labels,
                    'gmail_headers' => $inbound->headers,
                    'received_at' => $inbound->receivedAt,
                    'polled_at' => now(),
                ]
            );
        });
    }

    public function failed(\Throwable $exception): void
    {
        GmailSyncState::where('gmail_account_email', $this->gmailAccountEmail)
            ->update(['status' => 'error', 'last_error' => $exception->getMessage()]);

        Log::error('Gmail poll job failed permanently', [
            'account' => $this->gmailAccountEmail,
            'error' => $exception->getMessage(),
        ]);
    }
}
