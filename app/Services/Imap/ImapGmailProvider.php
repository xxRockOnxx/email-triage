<?php

namespace App\Services\Imap;

use App\Contracts\MailProviderContract;
use App\DTOs\InboundEmail;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message as ImapMessage;

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
    private ClientManager $clientManager;

    public function __construct(
        private readonly string $host,
        private readonly int $imapPort,
        private readonly int $smtpPort,
        private readonly string $username,
        private readonly string $appPassword,
    ) {
        $this->clientManager = new ClientManager();
    }

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
        $client = $this->connect();
        $inbox = $client->getFolder('INBOX');

        $sinceDate = $sinceCursor
            ? new \DateTimeImmutable($sinceCursor)
            : new \DateTimeImmutable('-'.config('gmail.initial_fetch_days', 3).' days');

        $query = $inbox->messages()->since($sinceDate)->leaveUnread();

        $messages = [];
        foreach ($query->get() as $imapMessage) {
            try {
                $messages[] = $this->parseMessage($imapMessage);
            } catch (\Throwable $e) {
                // Don't let one malformed message kill the whole poll.
                Log::warning('Failed to parse IMAP message, skipping', [
                    'uid' => $imapMessage->getUid(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $client->disconnect();

        return [
            'messages' => $messages,
            'next_cursor' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function connect(): \Webklex\PHPIMAP\Client
    {
        $client = $this->clientManager->make([
            'host' => $this->host,
            'port' => $this->imapPort,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => $this->username,
            'password' => $this->appPassword,
            'protocol' => 'imap',
        ]);

        try {
            $client->connect();
        } catch (ConnectionFailedException $e) {
            throw new \RuntimeException(
                'IMAP connection failed. Verify GMAIL_APP_PASSWORD is a valid App Password '.
                "(not your normal Gmail password) and that IMAP is enabled in Gmail settings: {$e->getMessage()}"
            );
        }

        return $client;
    }

    private function parseMessage(ImapMessage $imapMessage): InboundEmail
    {
        $from = $imapMessage->getFrom()[0] ?? null;
        $senderEmail = $from?->mail ?? 'unknown@unknown';
        $senderName = $from?->personal ?: null;

        $bodyText = $imapMessage->getTextBody() ?: strip_tags($imapMessage->getHTMLBody() ?? '');
        $bodyText = $this->stripQuotedReplies($bodyText);

        $headers = [];
        foreach (['list-unsubscribe', 'reply-to', 'in-reply-to', 'references'] as $headerName) {
            $value = $imapMessage->getHeader()->get($headerName);
            if ($value) {
                $headers[$headerName] = (string) $value;
            }
        }

        $labels = $this->mapGmailImapFlagsToLabels($imapMessage);

        return new InboundEmail(
            providerMessageId: (string) ($imapMessage->getMessageId() ?: $imapMessage->getUid()),
            providerThreadId: $this->resolveThreadId($imapMessage),
            senderEmail: $senderEmail,
            senderName: $senderName,
            subject: $imapMessage->getSubject() ?: '(no subject)',
            bodyText: $bodyText,
            labels: $labels,
            headers: $headers,
            receivedAt: \DateTimeImmutable::createFromMutable($imapMessage->getDate()->toDate()),
        );
    }

    /**
     * Gmail exposes thread grouping via the X-GM-THRID extension when
     * accessed over IMAP with Gmail's extensions enabled. Falls back to
     * References/In-Reply-To-derived grouping if unavailable.
     */
    private function resolveThreadId(ImapMessage $imapMessage): string
    {
        $gmThrId = $imapMessage->getHeader()->get('x-gm-thrid');
        if ($gmThrId) {
            return (string) $gmThrId;
        }

        $references = (string) ($imapMessage->getHeader()->get('references') ?? '');
        if ($references) {
            // First reference in the chain is a stable proxy for thread root.
            preg_match('/<([^>]+)>/', $references, $matches);

            return $matches[1] ?? (string) $imapMessage->getMessageId();
        }

        return (string) $imapMessage->getMessageId();
    }

    private function mapGmailImapFlagsToLabels(ImapMessage $imapMessage): array
    {
        $flags = array_map('strtoupper', $imapMessage->getFlags()->toArray());
        $labels = ['INBOX'];

        if (in_array('FLAGGED', $flags)) {
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

    public function archiveMessage(string $providerMessageId): void
    {
        // "Archive" in Gmail = remove from INBOX while keeping in All Mail.
        // Over IMAP this means moving out of the INBOX folder.
        $message = $this->findByMessageId($providerMessageId);
        $message?->move('[Gmail]/All Mail');
    }

    public function deleteMessage(string $providerMessageId): void
    {
        $message = $this->findByMessageId($providerMessageId);
        $message?->move('[Gmail]/Trash');
    }

    public function applyLabel(string $providerMessageId, string $label): void
    {
        // Gmail labels are implemented as IMAP folders/X-GM-LABELS. webklex/php-imap
        // doesn't expose X-GM-LABELS natively, so we approximate via a Gmail-style
        // folder copy, which Gmail surfaces as a label in its own UI.
        $client = $this->connect();
        $this->ensureFolderExists($client, $label);

        $message = $this->findByMessageId($providerMessageId, $client);
        $message?->copy($label);

        $client->disconnect();
    }

    private function ensureFolderExists(\Webklex\PHPIMAP\Client $client, string $folderName): void
    {
        if (! $client->getFolder($folderName)) {
            $client->createFolder($folderName);
        }
    }

    private function findByMessageId(string $providerMessageId, ?\Webklex\PHPIMAP\Client $client = null): ?ImapMessage
    {
        $ownClient = $client === null;
        $client ??= $this->connect();

        $message = $client->getFolder('INBOX')
            ->messages()
            ->where('MESSAGE_ID', $providerMessageId)
            ->get()
            ->first();

        if ($ownClient) {
            $client->disconnect();
        }

        return $message;
    }

    /**
     * Creates the reply as an actual Gmail draft via IMAP APPEND into the
     * Drafts folder — never auto-sent. The user opens Gmail (or this app)
     * to review and send it manually.
     */
    public function createDraftReply(string $providerThreadId, string $body): string
    {
        $client = $this->connect();

        $raw = "From: {$this->username}\r\n".
               "Subject: Re: (draft)\r\n".
               "Content-Type: text/plain; charset=UTF-8\r\n".
               "References: {$providerThreadId}\r\n".
               "In-Reply-To: {$providerThreadId}\r\n".
               "\r\n".
               $body;

        $draftsFolder = $client->getFolder('[Gmail]/Drafts') ?? $client->getFolder('Drafts');
        $draftsFolder->appendMessage($raw, ['\\Draft']);

        $client->disconnect();

        // IMAP APPEND doesn't return a usable id in a portable way across
        // servers; we return a synthetic id for audit logging purposes.
        return 'draft-'.now()->timestamp.'-'.substr(md5($body), 0, 8);
    }
}
