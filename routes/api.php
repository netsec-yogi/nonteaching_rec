<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\Applicant;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\RefereeController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------- public

Route::get('public/settings', [PublicController::class, 'settings']);
Route::get('public/advertisements', [PublicController::class, 'advertisements']);
Route::get('public/advertisements/{advertisement}', [PublicController::class, 'advertisement']);
Route::get('public/advertisements/{advertisement}/documents/{document}', [PublicController::class, 'document']);

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password');
    Route::get('me', [AuthController::class, 'me']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('email/resend', [AuthController::class, 'resendVerification'])->middleware('throttle:password');
        Route::post('password', [AuthController::class, 'changePassword']);
    });
});

// Referee: token-only access, no account.
Route::prefix('referee/{token}')->middleware('throttle:referee')->group(function () {
    Route::get('/', [RefereeController::class, 'show']);
    Route::post('code', [RefereeController::class, 'requestCode'])->middleware('throttle:referee-code');
    Route::post('verify', [RefereeController::class, 'verify'])->middleware('throttle:referee-code');
    Route::post('submit', [RefereeController::class, 'submit']);
});

// --------------------------------------------------------------- signed in

Route::middleware('auth:sanctum')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read', [NotificationController::class, 'markRead']);

    // Shared by applicants and staff; ApplicationPolicy::downloadDocuments decides.
    Route::get('applications/{application}/documents/{document}', [Applicant\DocumentController::class, 'download']);

    // ---------------------------------------------------------- applicant
    Route::middleware('role:applicant')->group(function () {
        Route::get('applicant/dashboard', [Applicant\ApplicationController::class, 'dashboard']);
        Route::post('applications', [Applicant\ApplicationController::class, 'store']);
        Route::get('applications/{application}', [Applicant\ApplicationController::class, 'show']);
        Route::delete('applications/{application}', [Applicant\ApplicationController::class, 'destroy']);
        Route::put('applications/{application}/sections/{section}', [Applicant\ApplicationController::class, 'saveSection']);
        Route::get('applications/{application}/preview', [Applicant\ApplicationController::class, 'preview']);
        Route::get('applications/{application}/preview.pdf', [Applicant\ApplicationController::class, 'previewPdf']);
        Route::post('applications/{application}/submit', [Applicant\ApplicationController::class, 'submit'])->middleware('throttle:submit');
        Route::get('applications/{application}/status', [Applicant\ApplicationController::class, 'status']);
        Route::get('applications/{application}/pdf/{version?}', [Applicant\ApplicationController::class, 'pdf']);
        Route::get('applications/{application}/receipt', [Applicant\ApplicationController::class, 'receipt']);
        Route::post('applications/{application}/withdraw', [Applicant\ApplicationController::class, 'withdraw']);
        Route::post('applications/{application}/documents', [Applicant\DocumentController::class, 'store'])->middleware('throttle:uploads');
        Route::delete('applications/{application}/documents/{document}', [Applicant\DocumentController::class, 'destroy']);
        Route::post('applications/{application}/clarifications/{clarification}', [Applicant\ClarificationController::class, 'respond'])->middleware('throttle:uploads');
    });

    // -------------------------------------------------------------- staff
    Route::prefix('admin')->middleware('role:staff')->group(function () {
        Route::get('dashboard', Admin\DashboardController::class);
        Route::get('advertisement-options', [Admin\AdvertisementController::class, 'options']);
        Route::get('user-options', [Admin\UserController::class, 'options']);
        Route::get('cycle-options', [Admin\CycleController::class, 'options']);
        Route::get('config-defaults', [Admin\SettingsController::class, 'defaults']);

        // Applications
        Route::get('applications', [Admin\ApplicationController::class, 'index']);
        Route::post('applications/bulk-assign', [Admin\ApplicationController::class, 'bulkAssign']);
        Route::post('applications/forward', [Admin\ApplicationController::class, 'forward']);
        Route::prefix('applications/{application}')->group(function () {
            Route::get('/', [Admin\ApplicationController::class, 'show']);
            Route::get('history', [Admin\ApplicationController::class, 'history']);
            Route::get('audit', [Admin\ApplicationController::class, 'audit']);
            Route::get('versions/{version}/pdf', [Admin\ApplicationController::class, 'versionPdf']);
            Route::get('scrutiny-report.pdf', [Admin\ApplicationController::class, 'scrutinyReportPdf']);
            Route::post('received', [Admin\ApplicationController::class, 'markReceived']);
            Route::post('payment', [Admin\ApplicationController::class, 'verifyPayment']);
            Route::post('endorsement', [Admin\ApplicationController::class, 'verifyEndorsement']);
            Route::post('documents/{document}/verify', [Admin\ApplicationController::class, 'verifyDocument']);
            Route::post('assign', [Admin\ApplicationController::class, 'assign']);
            Route::post('reopen', [Admin\ApplicationController::class, 'reopen']);
            Route::post('referee-email-exception', [Admin\ApplicationController::class, 'refereeEmailException']);
            Route::post('referee-waiver', [Admin\ApplicationController::class, 'refereeWaiver']);

            // Referees
            Route::post('referees/{position}/invite', [Admin\RefereeController::class, 'invite'])->whereNumber('position');
            Route::post('invitations/{invitation}/{action}', [Admin\RefereeController::class, 'action'])
                ->whereIn('action', ['resend', 'remind', 'revoke', 'replace', 'extend']);
            Route::get('recommendations/{response}', [Admin\RefereeController::class, 'show']);
            Route::get('recommendations/{response}/pdf', [Admin\RefereeController::class, 'pdf']);
            Route::post('recommendations/{response}/amend', [Admin\RefereeController::class, 'amend']);

            // Scrutiny workspace
            Route::get('scrutiny', [Admin\ScrutinyController::class, 'context']);
            Route::get('scrutiny/report', [Admin\ScrutinyController::class, 'report']);
            Route::put('scrutiny/items/{item}', [Admin\ScrutinyController::class, 'recordItem']);
            Route::post('scrutiny/observations', [Admin\ScrutinyController::class, 'addObservation']);
            Route::post('scrutiny/observations/{observation}/resolve', [Admin\ScrutinyController::class, 'resolveObservation']);
            Route::post('scrutiny/clarifications', [Admin\ScrutinyController::class, 'requestClarification']);
            Route::post('scrutiny/clarifications/{clarification}/resolve', [Admin\ScrutinyController::class, 'resolveClarification']);
            Route::post('scrutiny/review', [Admin\ScrutinyController::class, 'submitReview']);

            // Committee workspace
            Route::get('committee', [Admin\CommitteeController::class, 'context']);
            Route::post('committee/evaluation', [Admin\CommitteeController::class, 'evaluate']);
            Route::post('committee/decision', [Admin\CommitteeController::class, 'decide']);
        });

        Route::get('scrutiny/queue', [Admin\ScrutinyController::class, 'queue']);
        Route::get('committee/queue', [Admin\CommitteeController::class, 'queue']);
        Route::get('committees/{committee}/comparison', [Admin\CommitteeController::class, 'comparison']);

        // Reports and logs
        Route::get('reports', [Admin\ReportController::class, 'index']);
        Route::get('reports/{key}', [Admin\ReportController::class, 'show']);
        Route::get('reports/{key}/export/{format}', [Admin\ReportController::class, 'export'])->middleware('throttle:exports');
        Route::get('logs/audit', [Admin\LogController::class, 'audit']);
        Route::get('logs/emails', [Admin\LogController::class, 'emails']);
        Route::get('logs/access', [Admin\LogController::class, 'access']);
        Route::get('logs/exports', [Admin\LogController::class, 'exports']);

        // Advertisements (index/show visible to assigned staff; changes need advertisements.manage)
        Route::get('advertisements', [Admin\AdvertisementController::class, 'index']);
        Route::get('advertisements/{advertisement}', [Admin\AdvertisementController::class, 'show']);
        Route::get('advertisements/{advertisement}/documents/{document}', [Admin\AdvertisementController::class, 'downloadDocument']);
        Route::middleware(['permission:advertisements.manage', 'manages-advertisement'])->group(function () {
            Route::post('advertisements', [Admin\AdvertisementController::class, 'store']);
            Route::put('advertisements/{advertisement}', [Admin\AdvertisementController::class, 'update']);
            Route::delete('advertisements/{advertisement}', [Admin\AdvertisementController::class, 'destroy']);
            Route::post('advertisements/{advertisement}/status', [Admin\AdvertisementController::class, 'transition']);
            Route::post('advertisements/{advertisement}/documents', [Admin\AdvertisementController::class, 'uploadDocument']);
            Route::delete('advertisements/{advertisement}/documents/{document}', [Admin\AdvertisementController::class, 'deleteDocument']);
            Route::post('advertisements/{advertisement}/posts', [Admin\AdvertisementController::class, 'storePost']);
            Route::put('advertisements/{advertisement}/posts/{post}', [Admin\AdvertisementController::class, 'updatePost']);
            Route::delete('advertisements/{advertisement}/posts/{post}', [Admin\AdvertisementController::class, 'destroyPost']);
            Route::get('advertisements/{advertisement}/requirements', [Admin\DocumentRequirementController::class, 'index']);
            Route::post('advertisements/{advertisement}/requirements', [Admin\DocumentRequirementController::class, 'store']);
            Route::put('advertisements/{advertisement}/requirements/{requirement}', [Admin\DocumentRequirementController::class, 'update']);
            Route::delete('advertisements/{advertisement}/requirements/{requirement}', [Admin\DocumentRequirementController::class, 'destroy']);
            Route::get('advertisements/{advertisement}/stages', [Admin\ScrutinyStageController::class, 'index']);
            Route::put('advertisements/{advertisement}/stages', [Admin\ScrutinyStageController::class, 'sync']);
            Route::get('cycles', [Admin\CycleController::class, 'index']);
            Route::post('cycles', [Admin\CycleController::class, 'store']);
            Route::put('cycles/{cycle}', [Admin\CycleController::class, 'update']);
            Route::delete('cycles/{cycle}', [Admin\CycleController::class, 'destroy']);
        });

        Route::middleware('permission:users.manage')->group(function () {
            Route::put('advertisements/{advertisement}/administrators', [Admin\AdvertisementController::class, 'setAdministrators']);
            Route::get('advertisements/{advertisement}/teams', [Admin\TeamController::class, 'teams']);
            Route::post('advertisements/{advertisement}/teams', [Admin\TeamController::class, 'saveTeam']);
            Route::put('advertisements/{advertisement}/teams/{team}', [Admin\TeamController::class, 'saveTeam']);
            Route::delete('advertisements/{advertisement}/teams/{team}', [Admin\TeamController::class, 'deleteTeam']);
            Route::get('advertisements/{advertisement}/committees', [Admin\TeamController::class, 'committees']);
            Route::post('advertisements/{advertisement}/committees', [Admin\TeamController::class, 'saveCommittee']);
            Route::put('advertisements/{advertisement}/committees/{committee}', [Admin\TeamController::class, 'saveCommittee']);
            Route::delete('advertisements/{advertisement}/committees/{committee}', [Admin\TeamController::class, 'deleteCommittee']);
            Route::get('users', [Admin\UserController::class, 'index']);
            Route::get('roles', [Admin\UserController::class, 'roles']);
            Route::post('users', [Admin\UserController::class, 'store']);
            Route::put('users/{user}', [Admin\UserController::class, 'update']);
            Route::post('users/{user}/reset', [Admin\UserController::class, 'sendReset']);
            Route::get('permissions', [Admin\RoleController::class, 'index']);
            Route::put('roles/{role}/permissions', [Admin\RoleController::class, 'update']);
        });

        Route::middleware('permission:configuration.manage')->group(function () {
            Route::get('document-types', [Admin\DocumentTypeController::class, 'index']);
            Route::post('document-types', [Admin\DocumentTypeController::class, 'store']);
            Route::put('document-types/{documentType}', [Admin\DocumentTypeController::class, 'update']);
            Route::get('checklists', [Admin\ChecklistTemplateController::class, 'index']);
            Route::post('checklists', [Admin\ChecklistTemplateController::class, 'store']);
            Route::get('checklists/{checklistTemplate}', [Admin\ChecklistTemplateController::class, 'show']);
            Route::put('checklists/{checklistTemplate}', [Admin\ChecklistTemplateController::class, 'update']);
            Route::delete('checklists/{checklistTemplate}', [Admin\ChecklistTemplateController::class, 'destroy']);
            Route::post('checklists/{checklistTemplate}/copy', [Admin\ChecklistTemplateController::class, 'copy']);
            Route::post('checklists/{checklistTemplate}/assign', [Admin\ChecklistTemplateController::class, 'assign']);
            Route::delete('checklists/{checklistTemplate}/assignments/{assignment}', [Admin\ChecklistTemplateController::class, 'unassign']);
            Route::get('forms/{kind}', [Admin\FormTemplateController::class, 'index'])->whereIn('kind', ['referee', 'evaluation']);
            Route::post('forms/{kind}', [Admin\FormTemplateController::class, 'store'])->whereIn('kind', ['referee', 'evaluation']);
            Route::put('forms/{kind}/{id}', [Admin\FormTemplateController::class, 'update'])->whereIn('kind', ['referee', 'evaluation']);
            Route::delete('forms/{kind}/{id}', [Admin\FormTemplateController::class, 'destroy'])->whereIn('kind', ['referee', 'evaluation']);
        });

        Route::middleware('permission:templates.manage')->group(function () {
            Route::get('email-templates', [Admin\EmailTemplateController::class, 'index']);
            Route::put('email-templates/{emailTemplate}', [Admin\EmailTemplateController::class, 'update']);
            Route::post('email-templates/{emailTemplate}/preview', [Admin\EmailTemplateController::class, 'preview']);
        });

        Route::middleware('permission:settings.manage')->group(function () {
            Route::get('settings', [Admin\SettingsController::class, 'index']);
            Route::put('settings', [Admin\SettingsController::class, 'update']);
        });
    });
});
