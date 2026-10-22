<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * An uploaded file. `disk`, `path` and `sha256` never leave the server —
 * files are streamed through DocumentService after a policy check.
 */
class ApplicationDocument extends BaseModel
{
    use SoftDeletes;

    protected $hidden = ['disk', 'path', 'sha256'];

    protected $attributes = ['verification_status' => 'pending', 'is_frozen' => false];

    protected $casts = ['verified_at' => 'datetime', 'is_frozen' => 'boolean'];

    protected static function booted(): void
    {
        static::creating(fn (ApplicationDocument $doc) => $doc->uuid ??= (string) Str::uuid());
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
