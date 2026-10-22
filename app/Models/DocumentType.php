<?php

namespace App\Models;

class DocumentType extends BaseModel
{
    protected $casts = ['allowed_mimes' => 'array', 'is_active' => 'boolean'];
}
