<?php

namespace App\DTOs;

/**
 * Cheap, pre-aggregated signal about a sender's history — fed into the triage
 * prompt alongside RAG examples. Much cheaper than embedding search and often
 * more reliable for repeat senders (newsletters, automated notifications).
 */
class SenderReputationSummary
{
    public function __construct(
        public readonly string $senderEmail,
        public readonly int $emailCount,
        public readonly ?string $mostCommonCategory,
        public readonly ?float $avgUrgencyScore,
        public readonly ?float $avgConfidence,
        public readonly ?string $mostCommonAction,
    ) {}

    public function isNewSender(): bool
    {
        return $this->emailCount === 0;
    }
}
