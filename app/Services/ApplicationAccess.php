<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which applications a user may see. Every listing and every policy check goes
 * through here so the scoping rules exist in exactly one place.
 */
class ApplicationAccess
{
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $matched = false;

            if ($user->hasRole(Role::RECRUITMENT_ADMIN)) {
                $matched = true;
                $q->orWhereIn('applications.advertisement_id', $user->managedAdvertisements()->select('advertisements.id'));
            }

            if ($user->hasRole(Role::SCRUTINY_USER)) {
                $matched = true;
                $q->orWhere(function (Builder $s) use ($user) {
                    $s->whereNotNull('applications.submitted_at')->where(function (Builder $s2) use ($user) {
                        $s2->whereExists(fn ($e) => $e->selectRaw(1)->from('scrutiny_assignments')
                            ->whereColumn('scrutiny_assignments.application_id', 'applications.id')
                            ->where('scrutiny_assignments.user_id', $user->id)
                            ->where('scrutiny_assignments.status', '!=', 'withdrawn'))
                            ->orWhereExists(fn ($e) => $e->selectRaw(1)->from('scrutiny_teams')
                                ->join('scrutiny_team_members', 'scrutiny_team_members.scrutiny_team_id', '=', 'scrutiny_teams.id')
                                ->where('scrutiny_team_members.user_id', $user->id)
                                ->whereColumn('scrutiny_teams.advertisement_id', 'applications.advertisement_id')
                                ->where(fn ($p) => $p->whereNull('scrutiny_teams.post_id')
                                    ->orWhereColumn('scrutiny_teams.post_id', 'applications.post_id')));
                    });
                });
            }

            if ($user->hasRole(Role::COMMITTEE_MEMBER)) {
                $matched = true;
                $q->orWhereExists(fn ($e) => $e->selectRaw(1)->from('committee_assignments')
                    ->join('committee_members', 'committee_members.committee_id', '=', 'committee_assignments.committee_id')
                    ->whereColumn('committee_assignments.application_id', 'applications.id')
                    ->where('committee_members.user_id', $user->id));
            }

            if ($user->hasRole(Role::APPLICANT)) {
                $matched = true;
                $q->orWhere('applications.user_id', $user->id);
            }

            if (! $matched) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    public function canView(User $user, Application $application): bool
    {
        return $this->scope(Application::query()->whereKey($application->id), $user)->exists();
    }

    /** Staff-side visibility, ignoring the applicant-owner path. */
    public function canViewAsStaff(User $user, Application $application): bool
    {
        if (! $user->isStaff()) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $application->user_id !== $user->id && $this->canView($user, $application);
    }

    public function isCommitteeMemberFor(User $user, Application $application): bool
    {
        return $user->hasRole(Role::COMMITTEE_MEMBER) && \DB::table('committee_assignments')
            ->join('committee_members', 'committee_members.committee_id', '=', 'committee_assignments.committee_id')
            ->where('committee_assignments.application_id', $application->id)
            ->where('committee_members.user_id', $user->id)
            ->exists();
    }

    public function managesAdvertisement(User $user, int $advertisementId): bool
    {
        return $user->isSuperAdmin()
            || ($user->hasRole(Role::RECRUITMENT_ADMIN)
                && $user->managedAdvertisements()->where('advertisements.id', $advertisementId)->exists());
    }
}
