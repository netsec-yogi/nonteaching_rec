<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends BaseModel
{
    public const SUPER_ADMIN = 'super_admin';

    public const RECRUITMENT_ADMIN = 'recruitment_admin';

    public const SCRUTINY_USER = 'scrutiny_user';

    public const COMMITTEE_MEMBER = 'committee_member';

    public const APPLICANT = 'applicant';

    public const STAFF = [self::SUPER_ADMIN, self::RECRUITMENT_ADMIN, self::SCRUTINY_USER, self::COMMITTEE_MEMBER];

    protected $casts = ['is_staff' => 'boolean'];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
