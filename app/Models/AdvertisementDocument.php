<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdvertisementDocument extends BaseModel
{
    protected $casts = ['is_public' => 'boolean'];

    protected $hidden = ['disk', 'path'];

    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }
}
