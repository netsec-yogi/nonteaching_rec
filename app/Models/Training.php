<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphMany;

class Training extends BaseModel
{
    protected $table = 'application_trainings';

    public const TYPES = ['conducted' => 'Training conducted', 'attended' => 'Training attended', 'consultancy' => 'Consultancy service offered'];

    protected $casts = ['from_date' => 'date', 'to_date' => 'date'];

    public function documents(): MorphMany
    {
        return $this->morphMany(ApplicationDocument::class, 'documentable');
    }
}
