<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PiiMapping extends Model
{
    protected $fillable = [
        'email_id',
        'placeholder',
        'entity_type',
        'original_value_enc',
        'detection_score',
    ];

    protected function casts(): array
    {
        return [
            // The whole point of this table — never let this column persist unencrypted.
            'original_value_enc' => 'encrypted',
            'detection_score' => 'float',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
