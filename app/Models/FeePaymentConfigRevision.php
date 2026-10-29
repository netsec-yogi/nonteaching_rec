<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One version of a fee payment configuration. Never updated or deleted on its own. */
class FeePaymentConfigRevision extends BaseModel
{
    public const UPDATED_AT = null;

    protected $hidden = ['snapshot'];

    protected $casts = ['snapshot' => 'encrypted:array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fee payment configuration revisions are immutable.'));
    }

    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
