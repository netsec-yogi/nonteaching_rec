<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A frozen submission. Never updated or deleted. */
class ApplicationVersion extends BaseModel
{
    public const UPDATED_AT = null;

    protected $hidden = ['pdf_disk', 'pdf_path'];

    protected $casts = ['snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (ApplicationVersion $version) {
            // The PDF is attached once, immediately after the snapshot is written.
            if (array_diff(array_keys($version->getDirty()), ['pdf_disk', 'pdf_path', 'pdf_sha256'])
                || $version->getOriginal('pdf_path') !== null) {
                throw new LogicException('Application versions are immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Application versions are immutable.'));
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
