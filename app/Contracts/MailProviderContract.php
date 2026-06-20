<?php

namespace App\Contracts;

use App\DTOs\InboundEmail;

/**
 * Abstraction over the mail provider so Gmail isn't hardwired everywhere.
 * Only Gmail is implemented now, but jobs/services depend on this contract
 * so adding IMAP/Outlook later doesn't require touching the pipeline.
 */
interface MailProviderContract
{
    /**
     * Fetch new messages since the last sync cursor. Returns messages plus
     * the new cursor to persist for the next incremental poll.
     *
     * @return array{messages: InboundEmail[], next_cursor: ?string}
     */
    public function fetchNewMessages(?string $sinceCursor): array;

    public function archiveMessage(string $providerMessageId): void;

    public function deleteMessage(string $providerMessageId): void;

    public function applyLabel(string $providerMessageId, string $label): void;

    public function createDraftReply(string $providerThreadId, string $body): string;

    public function identifier(): string; // "gmail"
}
