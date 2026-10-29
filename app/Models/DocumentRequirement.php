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

    /** is_mandatory: true / false overrides the document type; null follows the document type's default. */
    protected $casts = ['is_mandatory' => 'boolean', 'accepts_undertaking' => 'boolean'];

    protected $appends = ['effective_mandatory', 'effective_accepts_undertaking'];

    /** Whether the document must be uploaded when this requirement applies. */
    public function isMandatory(): bool
    {
        if ($this->is_mandatory !== null) {
            return $this->is_mandatory;
        }

        return $this->relationLoaded('documentType')
            ? (bool) $this->documentType?->is_mandatory
            : (bool) DocumentType::whereKey($this->document_type_id)->value('is_mandatory');
    }

    /** Whether a signed undertaking may stand in for this document (requirement override, else the type's setting). */
    public function acceptsUndertaking(): bool
    {
        if ($this->accepts_undertaking !== null) {
            return $this->accepts_undertaking;
        }

        return $this->relationLoaded('documentType')
            ? (bool) $this->documentType?->accepts_undertaking
            : (bool) DocumentType::whereKey($this->document_type_id)->value('accepts_undertaking');
    }

    public function getEffectiveAcceptsUndertakingAttribute(): bool
    {
        return $this->acceptsUndertaking();
    }

    public function getEffectiveMandatoryAttribute(): bool
    {
        return $this->isMandatory();
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
