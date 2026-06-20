<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GmailSyncState extends Model
{
    protected $fillable = [
        'gmail_account_email',
        'sync_cursor',
        'last_polled_at',
        'status',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'last_polled_at' => 'datetime',
        ];
    }
}
