<?php

namespace App\DTOs;

/**
 * Raw message as fetched from the mail provider, before anonymization.
 * Carries real PII — handle with care, only passed between PollJob and
 * the persistence step, never to an LLM.
 */
class InboundEmail
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $providerThreadId,
        public readonly string $senderEmail,
        public readonly ?string $senderName,
        public readonly string $subject,
        public readonly string $bodyText,
        public readonly array $labels,
        public readonly array $headers,
        public readonly \DateTimeImmutable $receivedAt,
    ) {}

    public function senderDomain(): string
    {
        return strtolower(substr(strrchr($this->senderEmail, '@') ?: '', 1));
    }
}
