<?php

namespace App\Models;

class Setting extends BaseModel
{
    protected $casts = ['value' => 'json'];
}
