<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployerEndorsement extends BaseModel
{
    protected $attributes = ['verification_status' => 'pending'];

    protected $casts = [
        'employment_start_date' => 'date',
        'next_increment_date' => 'date',
        'endorsed_on' => 'date',
        'verified_at' => 'datetime',
        'basic_pay' => 'decimal:2',
        'no_disciplinary_case_certified' => 'boolean',
        'no_objection_certified' => 'boolean',
    ];

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
