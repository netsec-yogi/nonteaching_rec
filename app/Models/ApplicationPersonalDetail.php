<?php

namespace App\Models;

class ApplicationPersonalDetail extends BaseModel
{
    protected $casts = [
        'date_of_birth' => 'date',
        'is_pwd' => 'boolean',
        'is_ex_serviceman' => 'boolean',
        'permanent_same_as_correspondence' => 'boolean',
    ];
}
