<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrutinyItemResult extends BaseModel
{
    public const STATUSES = ['pending', 'verified', 'not_verified', 'not_applicable'];

    protected $attributes = ['status' => 'pending'];

    protected $casts = ['verified_at' => 'datetime'];

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ApplicationDocument::class, 'document_id');
    }
}
