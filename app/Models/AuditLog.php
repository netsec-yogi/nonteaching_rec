<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only. Written exclusively through App\Services\AuditLogger. */
class AuditLog extends BaseModel
{
    public const UPDATED_AT = null;

    protected $casts = ['old_values' => 'array', 'new_values' => 'array', 'meta' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are immutable.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
