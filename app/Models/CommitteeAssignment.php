<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommitteeAssignment extends BaseModel
{
    public function committee(): BelongsTo
    {
        return $this->belongsTo(Committee::class);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
