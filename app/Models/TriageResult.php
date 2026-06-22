<?php

namespace App\Models;

use App\Enums\SuggestedAction;
use App\Enums\TriageStatus;
use App\Enums\Urgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TriageResult extends Model
{
    protected $fillable = [
        'email_id',
        'category_id',
        'llm_category_id',
        'proposed_category_name',
        'proposed_category_reasoning',
        'summary',
        'triage_reasoning',
        'urgency',
        'llm_urgency',
        'confidence',
        'status',
        'suggested_action',
        'llm_suggested_action',
        'suggested_reply_draft',
        'llm_backend',
        'llm_model',
        'raw_llm_response',
        'rag_context_email_ids',
    ];

    protected function casts(): array
    {
        return [
            'urgency' => Urgency::class,
            'llm_urgency' => Urgency::class,
            'confidence' => 'integer',
            'status' => TriageStatus::class,
            'suggested_action' => SuggestedAction::class,
            'llm_suggested_action' => SuggestedAction::class,
            'raw_llm_response' => 'array',
            'rag_context_email_ids' => 'array',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The category the LLM originally predicted (before any user correction),
     * snapshotted at creation. Mirrors category() but on the immutable column.
     */
    public function llmCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'llm_category_id');
    }

    public function hasNewCategoryProposal(): bool
    {
        return $this->category_id === null && $this->proposed_category_name !== null;
    }
}
