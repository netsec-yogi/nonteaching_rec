<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Referee extends BaseModel
{
    protected $casts = [
        'is_resident_of_india' => 'boolean',
        'is_not_relative' => 'boolean',
        'is_employer_or_superior' => 'boolean',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RefereeInvitation::class)->orderByDesc('id');
    }

    public function response(): HasOne
    {
        return $this->hasOne(RefereeResponse::class);
    }
}
