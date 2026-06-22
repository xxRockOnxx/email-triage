<?php

namespace App\Services\Imap;

use App\Contracts\MailProviderContract;
use App\DTOs\InboundEmail;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\Message;
use Illuminate\Support\Facades\Log;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Gmail access via IMAP (fetch/archive/delete/label) + SMTP (drafts), using
 * a Gmail App Password rather than OAuth2. Requires 2FA enabled on the
 * Google account and an App Password generated at
 * https://myaccount.google.com/apppasswords — store it as GMAIL_APP_PASSWORD,
 * never the real account password.
 *
 * No Google API client library, no token refresh, no OAuth consent screen.
 * Trade-off: IMAP polling is less efficient than Gmail's history.list API
 * (no true incremental cursor), so "incremental sync" here means searching
 * for messages with an internal date after the last poll, deduped by
 * Message-ID against what we've already stored.
 */
class ImapGmailProvider implements MailProviderContract
{
    private ?Mailbox $mailbox = null;

    public function __construct(
        private readonly string $host,
        private readonly int $imapPort,
        private readonly int $smtpPort,
        private readonly string $username,
        private readonly string $appPassword,
    ) {}

    public function identifier(): string
    {
        return 'gmail-imap';
    }

    /**
     * $sinceCursor here is an ISO8601 timestamp string (last successful poll
     * time), not a Gmail historyId. Null on first run fetches the configured
     * initial backlog window instead of the whole mailbox.
     *
     * @return array{messages: InboundEmail[], next_cursor: ?string}
     */
    public function fetchNewMessages(?string $sinceCursor): array
    {
        $mailbox = $this->mailbox();

        try {
            $inbox = $mailbox->inbox();

            $sinceDate = $sinceCursor
                ? new \DateTimeImmutable($sinceCursor)
                : new \DateTimeImmutable('-'.config('gmail.initial_fetch_days', 3).' days');

            // imapengine fetches only UIDs by default, so we must opt into the
            // parts we actually parse: headers (subject/from/message-id/headers),
            // body (text/html — parse() combines head+body to split MIME parts),
            // and flags (\FLAGGED -> IMPORTANT). leaveUnread() forces BODY.PEEK
            // so fetching doesn't mark the messages \Seen while polling.
            $query = $inbox->messages()
                ->since($sinceDate)
                ->leaveUnread()
                ->withHeaders()
                ->withBody()
                ->withFlags();

            $messages = [];
            foreach ($query->get() as $imapMessage) {
                try {
                    $messages[] = $this->parseMessage($imapMessage);
                } catch (\Throwable $e) {
                    // Don't let one malformed message kill the whole poll.
                    Log::warning('Failed to parse IMAP message, skipping', [
                        'uid' => $imapMessage->uid(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return [
                'messages' => $messages,
                'next_cursor' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ];
        } finally {
            $this->disconnect();
        }
    }

    private function mailbox(): Mailbox
    {
        if ($this->mailbox) {
            return $this->mailbox;
        }

        $this->mailbox = new Mailbox([
            'host' => $this->host,
            'port' => $this->imapPort,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => $this->username,
            'password' => $this->appPassword,
        ]);

        try {
            // Force the lazy connection now so auth failures surface here with
            // a helpful message rather than deep inside a query.
            $this->mailbox->connect();
        } catch (\Throwable $e) {
            $this->mailbox = null;
            throw new \RuntimeException(
                'IMAP connection failed. Verify GMAIL_APP_PASSWORD is a valid App Password '.
                '(not your normal Gmail password) and that IMAP is enabled in Gmail settings: '.$e->getMessage()
            );
        }

        return $this->mailbox;
    }

    private function disconnect(): void
    {
        // ImapEngine's connection is lazy but not auto-closed, so we tear it
        // down after each operation to avoid holding a Gmail session open
        // between polls.
        $this->mailbox?->disconnect();
        $this->mailbox = null;
    }

    private function parseMessage(Message $imapMessage): InboundEmail
    {
        $from = $imapMessage->from();
        $senderEmail = $from?->email() ?? 'unknown@unknown';
        $senderName = $from?->name() ?: null;

        // Keep two representations: a plain-text/markdown body for the LLM
        // (anonymization, embeddings, search) and the raw HTML for rich
        // rendering in the frontend (DOMPurify sanitizes it client-side).
        $html = $imapMessage->html() ?: null;

        $plain = $imapMessage->text();
        if ($plain === '' && $html !== null) {
            // HTML-only mail (common for marketing/transactional): convert to
            // compact markdown rather than strip_tags, which runs words together.
            $plain = $this->htmlToMarkdown($html);
        }
        $bodyText = $this->normalizeWhitespace($this->stripQuotedReplies((string) $plain));
        $bodyText = strip_tags($bodyText); // safety belt for any leftover HTML that confuses the LLM

        $headers = [];
        foreach (['list-unsubscribe', 'reply-to', 'in-reply-to', 'references'] as $headerName) {
            $value = $imapMessage->header($headerName)?->getValue();
            if ($value) {
                $headers[$headerName] = (string) $value;
            }
        }

        $messageId = $imapMessage->messageId();
        if (empty($messageId)) {
            throw new \UnexpectedValueException(
                'IMAP message has no Message-ID header; cannot assign a stable provider id, skipping.'
            );
        }

        return new InboundEmail(
            providerMessageId: $messageId,
            providerThreadId: $this->resolveThreadId($imapMessage),
            senderEmail: $senderEmail,
            senderName: $senderName,
            subject: $imapMessage->subject() ?: '(no subject)',
            bodyText: $bodyText,
            bodyHtml: $html,
            labels: $this->mapGmailImapFlagsToLabels($imapMessage),
            headers: $headers,
            receivedAt: $imapMessage->date()?->toDateTimeImmutable() ?? new \DateTimeImmutable('now'),
        );
    }

    /**
     * Gmail's native X-GM-THRID is an IMAP FETCH attribute, not an RFC822
     * header, so ImapEngine (which parses only BODY[HEADER]) cannot read it.
     * Thread grouping therefore falls back to References / In-Reply-To.
     */
    private function resolveThreadId(Message $imapMessage): string
    {
        $references = (string) ($imapMessage->header('references')?->getValue() ?? '');
        if ($references) {
            // First reference in the chain is a stable proxy for thread root.
            preg_match('/<([^>]+)>/', $references, $matches);

            return $matches[1] ?? (string) ($imapMessage->messageId() ?? $imapMessage->uid());
        }

        return (string) ($imapMessage->messageId() ?? $imapMessage->uid());
    }

    private function mapGmailImapFlagsToLabels(Message $imapMessage): array
    {
        $flags = array_map('strtoupper', $imapMessage->flags());
        $labels = ['INBOX'];

        if (in_array('\FLAGGED', $flags)) {
            $labels[] = 'IMPORTANT';
        }

        return $labels;
    }

    private function stripQuotedReplies(string $text): string
    {
        $lines = explode("\n", $text);
        $cutoff = count($lines);

        foreach ($lines as $i => $line) {
            if (preg_match('/^On .+ wrote:$/', trim($line))
                || preg_match('/^-{2,}\s*Original Message\s*-{2,}/i', trim($line))
                || str_starts_with(trim($line), '>')) {
                $cutoff = $i;
                break;
            }
        }

        return trim(implode("\n", array_slice($lines, 0, $cutoff)));
    }

    /**
     * Convert an HTML string to compact markdown for the LLM path. Strips
     * <style>/<script> first since some malformed HTML confuses converters
     * into leaving CSS/JS behind. `strip_tags` drops tags with no markdown
     * equivalent (e.g. <div>) rather than leaving them inline.
     */
    private function htmlToMarkdown(string $html): string
    {
        $html = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', '', $html);

        $converter = new HtmlConverter([
            'strip_tags' => true,
            'hard_break' => false,
        ]);

        $converter->getEnvironment()->addConverter(new TableConverter());

        return $converter->convert($html);
    }

    /**
     * Collapse whitespace bloat that wastes tokens: repeated blank lines,
     * trailing spaces, leftover HTML entities, double-spacing.
     */
    private function normalizeWhitespace(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+$/m', '', $text);   // trailing spaces per line
        $text = preg_replace("/\n{3,}/", "\n\n", $text); // 3+ newlines -> paragraph break
        $text = preg_replace('/[ \t]{2,}/', ' ', $text); // collapse runs of spaces

        return trim($text);
    }

    public function archiveMessage(string $providerMessageId): void
    {
        // "Archive" in Gmail = remove from INBOX while keeping in All Mail.
        // Over IMAP this means moving out of the INBOX folder.
        try {
            $message = $this->findByMessageId($providerMessageId);
            $message?->move('[Gmail]/All Mail');
        } finally {
            $this->disconnect();
        }
    }

    public function deleteMessage(string $providerMessageId): void
    {
        try {
            $message = $this->findByMessageId($providerMessageId);
            if (! $message) {
                Log::warning('Cannot delete message: not found', [
                    'message_id' => $providerMessageId,
                ]);
                return;
            }
            $message->move('[Gmail]/Trash');
        } catch (\Throwable $e) {
            Log::error('Failed to delete message', [
                'message_id' => $providerMessageId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->disconnect();
        }
    }

    public function restoreMessage(string $providerMessageId): void
    {
        try {
            $message = $this->findDeletedByMessageId($providerMessageId);
            if (! $message) {
                Log::warning('Cannot restore message: not found', [
                    'message_id' => $providerMessageId,
                ]);
                return;
            }
            $message->move('INBOX');
        } catch (\Throwable $e) {
            Log::error('Failed to restore message', [
                'message_id' => $providerMessageId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->disconnect();
        }
    }

    public function applyLabel(string $providerMessageId, string $label): void
    {
        // Gmail labels are X-GM-LABELS. We set them directly via a raw STORE
        // (which also makes EmailActionService::undo()'s applyLabel(id,'INBOX')
        // correctly restore inbox placement, since INBOX is a Gmail system
        // label). Falls back to a folder copy if the server rejects it.
        try {
            $message = $this->findByMessageId($providerMessageId);
            if (! $message) {
                return;
            }

            try {
                $this->mailbox()->connection()->store(
                    [$label],
                    [$message->uid()],
                    mode: '+',
                    item: 'X-GM-LABELS',
                );
            } catch (\Throwable $e) {
                Log::warning('X-GM-LABELS store failed, falling back to folder copy', [
                    'label' => $label,
                    'error' => $e->getMessage(),
                ]);

                $this->mailbox()->folders()->firstOrCreate($label);
                $message->copy($label);
            }
        } finally {
            $this->disconnect();
        }
    }

    private function findByMessageId(string $providerMessageId): ?Message
    {
        return $this->mailbox()->inbox()
            ->messages()
            ->messageId($providerMessageId)
            ->get()
            ->first();
    }

    private function findDeletedByMessageId(string $providerMessageId): ?Message
    {
        return $this->mailbox()
            ->folders()
            ->findOrFail('[Gmail]/Trash')
            ->messages()
            ->messageId($providerMessageId)
            ->first();
    }

    /**
     * Creates the reply as an actual Gmail draft via IMAP APPEND into the
     * Drafts folder — never auto-sent. The user opens Gmail (or this app)
     * to review and send it manually.
     */
    public function createDraftReply(string $providerThreadId, string $body): string
    {
        try {
            $mailbox = $this->mailbox();

            $draftsFolder = $mailbox->folders()->find('[Gmail]/Drafts')
                ?? $mailbox->folders()->find('Drafts');

            if (! $draftsFolder) {
                throw new \RuntimeException('Gmail Drafts folder not found.');
            }

            $raw = "From: {$this->username}\r\n".
                   "Subject: Re: (draft)\r\n".
                   "Content-Type: text/plain; charset=UTF-8\r\n".
                   "References: {$providerThreadId}\r\n".
                   "In-Reply-To: {$providerThreadId}\r\n".
                   "\r\n".
                   $body;

            $draftsFolder->messages()->append($raw, ['\Draft']);
        } finally {
            $this->disconnect();
        }

        // IMAP APPEND doesn't return a usable id in a portable way across
        // servers; we return a synthetic id for audit logging purposes.
        return 'draft-'.now()->timestamp.'-'.substr(md5($body), 0, 8);
    }
}
