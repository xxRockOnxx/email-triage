<?php

namespace App\Enums;

enum Urgency: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Numeric weight for averaging into sender_stats.avg_urgency_score
     * and for sorting the triage queue by urgency.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public static function fromWeight(float $weight): self
    {
        return match (true) {
            $weight >= 3.5 => self::Critical,
            $weight >= 2.5 => self::High,
            $weight >= 1.5 => self::Medium,
            default => self::Low,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
