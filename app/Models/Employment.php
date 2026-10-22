<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;

class Employment extends BaseModel
{
    protected $table = 'application_employments';

    public const TYPES = ['regular' => 'Regular', 'temporary' => 'Temporary', 'permanent' => 'Permanent', 'contract' => 'Contract'];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'is_current' => 'boolean',
        'basic_pay' => 'decimal:2',
        'gross_pay' => 'decimal:2',
    ];

    public function documents(): MorphMany
    {
        return $this->morphMany(ApplicationDocument::class, 'documentable');
    }
}
