<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRequirement extends BaseModel
{
    public const CONDITIONS = [
        'always' => 'Always',
        'reserved_category' => 'Category other than UR',
        'pwd' => 'Candidate with disability',
        'ex_serviceman' => 'Ex-serviceman',
        'in_service' => 'In-service candidate',
        'has_employment' => 'Candidate has employment history',
        'criminal_disclosure' => 'Candidate disclosed a case or proceeding',
        'fee_payable' => 'Application fee payable',
    ];

    protected $casts = ['is_mandatory' => 'boolean'];

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
