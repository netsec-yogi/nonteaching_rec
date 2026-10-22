<?php

namespace App\Models;

use App\Services\AccountMailer;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    private ?Collection $permissionCache = null;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    public function managedAdvertisements(): BelongsToMany
    {
        return $this->belongsToMany(Advertisement::class)->withTimestamps();
    }

    public function scrutinyTeams(): BelongsToMany
    {
        return $this->belongsToMany(ScrutinyTeam::class, 'scrutiny_team_members')->withTimestamps();
    }

    public function committees(): BelongsToMany
    {
        return $this->belongsToMany(Committee::class, 'committee_members')->withPivot('is_chair')->withTimestamps();
    }

    public function scrutinyAssignments(): HasMany
    {
        return $this->hasMany(ScrutinyAssignment::class);
    }

    public function hasRole(string ...$slugs): bool
    {
        $this->loadMissing('roles');

        return $this->roles->whereIn('slug', $slugs)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    public function isStaff(): bool
    {
        return $this->hasRole(...Role::STAFF);
    }

    /**
     * Permission slugs granted through any of the user's roles. The Super
     * Administrator implicitly holds every permission.
     */
    public function permissionSlugs(): Collection
    {
        if ($this->permissionCache === null) {
            $this->loadMissing('roles.permissions');
            $this->permissionCache = $this->isSuperAdmin()
                ? Permission::query()->pluck('slug')
                : $this->roles->flatMap->permissions->pluck('slug')->unique()->values();
        }

        return $this->permissionCache;
    }

    public function hasPermission(string $slug): bool
    {
        return $this->isSuperAdmin() || $this->permissionSlugs()->contains($slug);
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
    }

    public function sendEmailVerificationNotification(): void
    {
        app(AccountMailer::class)->sendVerification($this);
    }

    public function sendPasswordResetNotification($token): void
    {
        app(AccountMailer::class)->sendPasswordReset($this, $token);
    }
}
