<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Correction extends Model
{
    protected $fillable = [
        'triage_result_id',
        'old_category_id',
        'new_category_id',
        'old_urgency',
        'new_urgency',
        'old_suggested_action',
        'new_suggested_action',
        'note',
    ];

    protected function casts(): array
    {
        return [];
    }

    public function triageResult(): BelongsTo
    {
        return $this->belongsTo(TriageResult::class);
    }

    public function oldCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'old_category_id');
    }

    public function newCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'new_category_id');
    }
}
