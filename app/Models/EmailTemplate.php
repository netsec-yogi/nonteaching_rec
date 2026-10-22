<?php

namespace App\Models;

class EmailTemplate extends BaseModel
{
    protected $casts = ['placeholders' => 'array', 'is_active' => 'boolean'];
}
