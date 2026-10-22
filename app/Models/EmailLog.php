<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class EmailLog extends BaseModel
{
    protected $attributes = ['status' => 'queued', 'attempts' => 0];

    protected $casts = ['queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];

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
}
