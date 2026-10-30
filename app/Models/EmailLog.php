<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One outgoing email. Status: queued → processing → sent (accepted by the mail server, which does
 * not confirm delivery to the inbox) | retrying | rate_limited | failed.
 */
class EmailLog extends BaseModel
{
    public const STATUSES = ['queued', 'processing', 'sent', 'retrying', 'rate_limited', 'failed'];

    protected $attributes = ['status' => 'queued', 'attempts' => 0];

    protected $casts = [
        'queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime',
        'processing_started_at' => 'datetime', 'last_attempt_at' => 'datetime', 'next_attempt_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (EmailLog $log) => $log->uuid ??= (string) Str::uuid());
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /** Named apart from the `attempts` column, which counts them. */
    public function sendAttempts(): HasMany
    {
        return $this->hasMany(EmailAttempt::class)->orderBy('id');
    }
}
