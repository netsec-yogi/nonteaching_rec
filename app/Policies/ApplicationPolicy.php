<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\Role;
use App\Models\User;
use App\Services\ApplicationAccess;

/**
 * Application-level abilities. Visibility comes from ApplicationAccess (which
 * advertisements, teams, assignments and committees a user belongs to);
 * capability comes from permissions granted to the user's roles.
 */
class ApplicationPolicy
{
    public function __construct(private ApplicationAccess $access) {}

    private function owns(User $user, Application $application): bool
    {
        return $application->user_id === $user->id && $user->hasRole(Role::APPLICANT);
    }

    /** Staff who can see this application at all. */
    public function viewAsStaff(User $user, Application $application): bool
    {
        if (! $user->isStaff() || ! $this->access->canViewAsStaff($user, $application)) {
            return false;
        }

        return $user->isSuperAdmin()
            || $user->hasPermission('applications.view')
            || $user->hasRole(Role::SCRUTINY_USER, Role::COMMITTEE_MEMBER);
    }

    public function viewOwn(User $user, Application $application): bool
    {
        return $this->owns($user, $application);
    }

    public function edit(User $user, Application $application): bool
    {
        return $this->owns($user, $application) && $application->isEditableByApplicant();
    }

    public function submit(User $user, Application $application): bool
    {
        return $this->edit($user, $application);
    }

    public function withdraw(User $user, Application $application): bool
    {
        return $this->owns($user, $application) && $application->status !== Application::WITHDRAWN;
    }

    public function downloadDocuments(User $user, Application $application): bool
    {
        return $this->owns($user, $application)
            || ($this->viewAsStaff($user, $application) && $user->hasPermission('documents.download'));
    }

    public function viewRefereeStatus(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('referees.status');
    }

    /** Confidential content. Never the applicant, whatever else they hold. */
    public function viewRecommendations(User $user, Application $application): bool
    {
        return $application->user_id !== $user->id
            && $this->viewAsStaff($user, $application)
            && $user->hasPermission('referees.recommendations');
    }

    public function manageReferees(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('referees.manage');
    }

    public function refereeExceptions(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('referees.exceptions');
    }

    public function verify(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('applications.verify');
    }

    public function assign(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('applications.assign');
    }

    public function reopen(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('applications.reopen');
    }

    public function correspond(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('applications.correspond');
    }

    public function scrutinize(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('scrutiny.perform');
    }

    public function evaluate(User $user, Application $application): bool
    {
        return ($this->access->isCommitteeMemberFor($user, $application) && $user->hasPermission('committee.evaluate'))
            || ($user->isSuperAdmin() && $application->committeeAssignments()->exists());
    }

    public function viewHistory(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('applications.history');
    }

    public function viewAudit(User $user, Application $application): bool
    {
        return $this->viewAsStaff($user, $application) && $user->hasPermission('audit.view');
    }
}
