<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Permission extends BaseModel
{
    protected $casts = ['is_sensitive' => 'boolean'];

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}
