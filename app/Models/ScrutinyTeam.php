<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ScrutinyTeam extends BaseModel
{
    public function advertisement(): BelongsTo
    {
        return $this->belongsTo(Advertisement::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'scrutiny_team_members')->withTimestamps();
    }
}
