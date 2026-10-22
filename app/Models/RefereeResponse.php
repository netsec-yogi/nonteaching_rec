<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A submitted recommendation. Immutable: corrections are recorded as
 * RefereeResponseAmendment rows, never written over the original.
 */
class RefereeResponse extends BaseModel
{
    public const UPDATED_AT = null;

    protected $hidden = ['pdf_disk', 'pdf_path', 'ip_address', 'user_agent'];

    protected $casts = [
        'candidate_entered' => 'array',
        'answers' => 'array',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (RefereeResponse $response) {
            // The generated PDF is attached once, after the transaction that wrote the response.
            if (array_diff(array_keys($response->getDirty()), ['pdf_disk', 'pdf_path', 'pdf_sha256'])
                || $response->getOriginal('pdf_path') !== null) {
                throw new LogicException('Referee responses are immutable. Record an amendment instead.');
            }
        });
        static::deleting(fn () => throw new LogicException('Referee responses cannot be deleted.'));
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(RefereeInvitation::class, 'referee_invitation_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function referee(): BelongsTo
    {
        return $this->belongsTo(Referee::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(RefereeResponseAmendment::class)->orderBy('id');
    }
}
