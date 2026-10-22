<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistTemplate extends BaseModel
{
    use SoftDeletes;

    protected $casts = ['archived_at' => 'datetime'];

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sort_order');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ChecklistAssignment::class);
    }

    /** True once any scrutiny result or applicant entry references one of its items. */
    public function isInUse(): bool
    {
        $itemIds = ChecklistItem::withTrashed()->where('checklist_template_id', $this->id)->pluck('id');

        return ScrutinyItemResult::whereIn('checklist_item_id', $itemIds)->exists()
            || ApplicationChecklistEntry::whereIn('checklist_item_id', $itemIds)->exists();
    }
}
