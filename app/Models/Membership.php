<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;

class Membership extends BaseModel
{
    protected $table = 'application_memberships';

    protected $casts = ['member_since' => 'date'];

    public function documents(): MorphMany
    {
        return $this->morphMany(ApplicationDocument::class, 'documentable');
    }
}
