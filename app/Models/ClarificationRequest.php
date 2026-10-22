<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClarificationRequest extends BaseModel
{
    protected $attributes = ['status' => 'open', 'allow_uploads' => true];

    protected $casts = [
        'checklist_item_ids' => 'array',
        'allow_uploads' => 'boolean',
        'due_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ClarificationResponse::class)->orderBy('id');
    }

    public function isOpenForResponse(): bool
    {
        return in_array($this->status, ['open', 'responded'], true) && $this->due_at->isFuture();
    }
}
