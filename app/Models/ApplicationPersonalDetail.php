<?php

namespace App\Models;

class ApplicationPersonalDetail extends BaseModel
{
    /** Full identity numbers never go out by serialisation; see IdentityNumbers for masked display. */
    protected $hidden = ['pan_number', 'aadhaar_number'];

    protected $casts = [
        'pan_number' => 'encrypted',
        'aadhaar_number' => 'encrypted',
        'date_of_birth' => 'date',
        'is_pwd' => 'boolean',
        'is_ex_serviceman' => 'boolean',
        'permanent_same_as_correspondence' => 'boolean',
    ];
}
