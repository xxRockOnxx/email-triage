<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionLog extends Model
{
    protected $table = 'actions_log';

    protected $fillable = [
        'email_id',
        'triage_result_id',
        'action_type',
        'initiated_by',
        'payload',
        'gmail_action_id',
        'executed_at',
        'undone_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'executed_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function triageResult(): BelongsTo
    {
        return $this->belongsTo(TriageResult::class);
    }

    public function isUndone(): bool
    {
        return $this->undone_at !== null;
    }

    public function isUndoable(): bool
    {
        return in_array($this->action_type, ['archive', 'delete', 'label_applied'])
            && ! $this->isUndone();
    }
}
