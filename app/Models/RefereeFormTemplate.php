<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RefereeFormTemplate extends BaseModel
{
    use SoftDeletes;

    protected $casts = ['recommendation_options' => 'array', 'is_active' => 'boolean'];

    public function questions(): HasMany
    {
        return $this->hasMany(RefereeQuestion::class)->orderBy('sort_order');
    }
}
