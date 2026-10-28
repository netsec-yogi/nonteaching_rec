<?php

namespace App\Support;

use App\Models\Role;

/**
 * Every grantable permission, with the roles that hold it on a fresh install.
 * The Super Administrator holds all of them implicitly; the rest can be
 * re-assigned from the Roles & Permissions screen.
 */
final class Permissions
{
    /** slug => [name, group, sensitive, default roles] */
    public const ALL = [
        'advertisements.manage' => ['Create and manage advertisements, posts and vacancies', 'configuration', false, []],
        'configuration.manage' => ['Manage checklists, document types, referee and evaluation forms', 'configuration', false, []],
        'templates.manage' => ['Edit email templates', 'configuration', false, []],
        'settings.manage' => ['Edit system settings and retention policy', 'configuration', false, []],
        'users.manage' => ['Create users, assign roles, teams and committees', 'administration', true, []],
        'audit.view' => ['View audit logs and access logs', 'administration', true, []],
        'email_logs.view' => ['View email delivery logs', 'administration', false, [Role::RECRUITMENT_ADMIN]],

        'applications.view' => ['View applications in assigned advertisements', 'applications', false, [Role::RECRUITMENT_ADMIN]],
        'applications.verify' => ['Verify fee, documents and employer endorsement', 'applications', false, [Role::RECRUITMENT_ADMIN, Role::SCRUTINY_USER]],
        'applications.assign' => ['Assign applications for scrutiny and forward to committee', 'applications', false, [Role::RECRUITMENT_ADMIN]],
        'applications.reopen' => ['Reopen submitted applications for correction', 'applications', false, []],
        'applications.correspond' => ['Send clarification requests to candidates', 'applications', false, [Role::RECRUITMENT_ADMIN, Role::SCRUTINY_USER]],
        'applications.history' => ['View application history and audit trail', 'applications', false, [Role::RECRUITMENT_ADMIN]],

        'documents.download' => ['Open and download applicant documents', 'documents', true, [Role::RECRUITMENT_ADMIN, Role::SCRUTINY_USER, Role::COMMITTEE_MEMBER]],

        'referees.status' => ['View referee invitation and recommendation status', 'referees', false, [Role::RECRUITMENT_ADMIN, Role::SCRUTINY_USER, Role::COMMITTEE_MEMBER]],
        'referees.recommendations' => ['Read confidential referee recommendations', 'referees', true, [Role::RECRUITMENT_ADMIN, Role::COMMITTEE_MEMBER]],
        'referees.manage' => ['Resend, remind, revoke, replace and extend referee invitations', 'referees', false, [Role::RECRUITMENT_ADMIN]],
        'referees.exceptions' => ['Grant referee exceptions and record amendments', 'referees', false, []],

        'scrutiny.perform' => ['Perform scrutiny on assigned applications', 'scrutiny', false, [Role::SCRUTINY_USER, Role::RECRUITMENT_ADMIN]],

        'committee.evaluate' => ['Evaluate applications assigned to the committee', 'committee', false, [Role::COMMITTEE_MEMBER]],
        'committee.record_decision' => ['Record the committee decision (in addition to the chair)', 'committee', false, []],

        'reports.view' => ['View recruitment reports', 'reports', false, [Role::RECRUITMENT_ADMIN]],
        'exports.applications' => ['Export consolidated application data', 'reports', true, [Role::RECRUITMENT_ADMIN]],
        'exports.referee_content' => ['Export confidential referee recommendation content', 'reports', true, []],
    ];
}
