<?php

namespace App\Models;

use App\Enums\CategorySource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'source',
        'gmail_label_id',
        'status',
        'merged_into_id',
        'parent_category_id',
        'confidence_threshold',
        'is_system_default',
    ];

    protected function casts(): array
    {
        return [
            'source' => CategorySource::class,
            'is_system_default' => 'boolean',
            'confidence_threshold' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_category_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_category_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'merged_into_id');
    }

    public function triageResults(): HasMany
    {
        return $this->hasMany(TriageResult::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Effective threshold for auto-filing: per-category override, else global default.
     */
    public function effectiveConfidenceThreshold(): int
    {
        return $this->confidence_threshold ?? config('triage.default_confidence_threshold', 75);
    }
}
