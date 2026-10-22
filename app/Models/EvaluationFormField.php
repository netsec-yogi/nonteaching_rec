<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

class EvaluationFormField extends BaseModel
{
    use SoftDeletes;

    protected $casts = ['options' => 'array', 'is_required' => 'boolean'];
}
