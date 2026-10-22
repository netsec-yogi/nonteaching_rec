<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;

class Qualification extends BaseModel
{
    protected $table = 'application_qualifications';

    public const LEVELS = [
        'class_10' => 'Class 10 / Matriculation',
        'class_12' => 'Class 12 / Higher Secondary',
        'diploma' => 'Diploma',
        'graduation' => 'Graduation',
        'post_graduation' => 'Post Graduation',
        'doctorate' => 'Doctorate',
        'professional' => 'Professional Qualification',
        'technical' => 'Technical Qualification / Certificate',
        'training' => 'Training',
        'other' => 'Other',
    ];

    protected $casts = ['percentage' => 'decimal:2', 'year_of_passing' => 'integer'];

    public function documents(): MorphMany
    {
        return $this->morphMany(ApplicationDocument::class, 'documentable');
    }
}
