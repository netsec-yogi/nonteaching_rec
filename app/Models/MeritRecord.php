<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;

class MeritRecord extends BaseModel
{
    protected $table = 'application_merit_records';

    protected $casts = ['awarded_on' => 'date'];

    public function documents(): MorphMany
    {
        return $this->morphMany(ApplicationDocument::class, 'documentable');
    }
}
