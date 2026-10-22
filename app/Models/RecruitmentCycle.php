<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecruitmentCycle extends BaseModel
{
    use SoftDeletes;

    protected $casts = ['is_active' => 'boolean'];

    public function advertisements(): HasMany
    {
        return $this->hasMany(Advertisement::class);
    }
}
