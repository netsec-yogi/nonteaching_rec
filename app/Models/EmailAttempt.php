<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One try at handing an email to one sending account. */
class EmailAttempt extends BaseModel
{
    public $timestamps = false;

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function emailLog(): BelongsTo
    {
        return $this->belongsTo(EmailLog::class);
    }
}
