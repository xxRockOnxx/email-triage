<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SenderStat extends Model
{
    protected $table = 'sender_stats';

    protected $fillable = [
        'sender_email',
        'sender_domain',
        'email_count',
        'category_histogram',
        'avg_urgency_score',
        'avg_confidence',
        'action_histogram',
        'recent_actions',
        'last_seen_at',
        'stats_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'category_histogram' => 'array',
            'avg_urgency_score' => 'float',
            'avg_confidence' => 'float',
            'action_histogram' => 'array',
            'recent_actions' => 'array',
            'last_seen_at' => 'datetime',
            'stats_updated_at' => 'datetime',
        ];
    }

    public function mostCommonCategory(): ?string
    {
        if (empty($this->category_histogram)) {
            return null;
        }

        arsort($this->category_histogram);

        return array_key_first($this->category_histogram);
    }

    public function mostCommonAction(): ?string
    {
        if (empty($this->action_histogram)) {
            return null;
        }

        $histogram = $this->action_histogram;
        arsort($histogram);

        return array_key_first($histogram);
    }
}
