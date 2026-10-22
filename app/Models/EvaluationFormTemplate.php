<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EvaluationFormTemplate extends BaseModel
{
    use SoftDeletes;

    protected $casts = ['recommendation_options' => 'array', 'is_active' => 'boolean'];

    public function fields(): HasMany
    {
        return $this->hasMany(EvaluationFormField::class)->orderBy('sort_order');
    }
}
