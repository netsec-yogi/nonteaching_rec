<?php

namespace App\Models;

class DocumentType extends BaseModel
{
    protected $casts = ['allowed_mimes' => 'array', 'is_active' => 'boolean', 'is_mandatory' => 'boolean', 'accepts_undertaking' => 'boolean'];

    /** Default for every advertisement requirement of this type that does not override it. */
    protected $attributes = ['is_mandatory' => true, 'accepts_undertaking' => false];
}
