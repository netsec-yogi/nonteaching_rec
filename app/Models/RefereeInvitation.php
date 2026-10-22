<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class RefereeInvitation extends BaseModel
{
    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const REVOKED = 'revoked';

    public const REPLACED = 'replaced';

    protected $hidden = ['token_hash', 'token_encrypted', 'verification_code_hash'];

    protected $attributes = [
        'state' => self::ACTIVE,
        'delivery_status' => 'not_sent',
        'open_count' => 0,
        'reminder_count' => 0,
        'verification_attempts' => 0,
    ];

    protected $casts = [
        'token_encrypted' => 'encrypted',
        'expires_at' => 'datetime',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivery_failed_at' => 'datetime',
        'first_opened_at' => 'datetime',
        'last_opened_at' => 'datetime',
        'last_reminder_at' => 'datetime',
        'verification_code_expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'completed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(fn (RefereeInvitation $i) => $i->uuid ??= (string) Str::uuid());
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public function isExpired(): bool
    {
        return $this->state === self::ACTIVE && $this->expires_at->isPast();
    }

    /**
     * One status for display, combining state and expiry:
     * pending | completed | expired | revoked | replaced
     */
    public function recommendationStatus(): string
    {
        if ($this->state === self::ACTIVE) {
            return $this->isExpired() ? 'expired' : 'pending';
        }

        return $this->state;
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(Referee::class);
    }

    public function response(): HasOne
    {
        return $this->hasOne(RefereeResponse::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }
}
