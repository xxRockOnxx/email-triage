<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Email extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'gmail_id',
        'thread_id',
        'sender_email',
        'sender_name',
        'sender_domain',
        'subject_enc',
        'body_enc',
        'body_html_enc',
        'anonymized_subject',
        'anonymized_body',
        'gmail_labels',
        'gmail_headers',
        'is_anonymized',
        'anonymized_at',
        'received_at',
        'polled_at',
    ];

    protected function casts(): array
    {
        return [
            // Encrypted at rest — real content. Decrypted transparently on access
            // by Eloquent, but never leaves PHP-land unencrypted on disk.
            'subject_enc' => 'encrypted',
            'body_enc' => 'encrypted',
            'body_html_enc' => 'encrypted',
            'gmail_labels' => 'array',
            'gmail_headers' => 'array',
            'is_anonymized' => 'boolean',
            'anonymized_at' => 'datetime',
            'received_at' => 'datetime',
            'polled_at' => 'datetime',
        ];
    }

    public function piiMappings(): HasMany
    {
        return $this->hasMany(PiiMapping::class);
    }

    public function triageResults(): HasMany
    {
        return $this->hasMany(TriageResult::class);
    }

    public function latestTriageResult(): HasOne
    {
        return $this->hasOne(TriageResult::class)->latestOfMany();
    }

    public function actionsLog(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }

    public function scopeNeedsReview($query)
    {
        return $query->whereHas('latestTriageResult', fn ($q) => $q->where('status', 'needs_review'));
    }

    public function scopeAutoFiled($query)
    {
        return $query->whereHas('latestTriageResult', fn ($q) => $q->where('status', 'auto_filed'));
    }
}
