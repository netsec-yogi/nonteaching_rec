<?php

namespace App\Providers;

use App\Models;
use App\Services\SettingsService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Stable aliases instead of class names in polymorphic columns.
        Relation::enforceMorphMap([
            'user' => Models\User::class,
            'application' => Models\Application::class,
            'advertisement' => Models\Advertisement::class,
            'post' => Models\Post::class,
            'qualification' => Models\Qualification::class,
            'employment' => Models\Employment::class,
            'merit_record' => Models\MeritRecord::class,
            'training' => Models\Training::class,
            'membership' => Models\Membership::class,
            'application_document' => Models\ApplicationDocument::class,
            'payment' => Models\Payment::class,
            'endorsement' => Models\EmployerEndorsement::class,
            'referee' => Models\Referee::class,
            'referee_invitation' => Models\RefereeInvitation::class,
            'referee_response' => Models\RefereeResponse::class,
            'referee_amendment' => Models\RefereeResponseAmendment::class,
            'scrutiny_assignment' => Models\ScrutinyAssignment::class,
            'scrutiny_item_result' => Models\ScrutinyItemResult::class,
            'scrutiny_review' => Models\ScrutinyReview::class,
            'scrutiny_observation' => Models\ScrutinyObservation::class,
            'clarification_request' => Models\ClarificationRequest::class,
            'clarification_response' => Models\ClarificationResponse::class,
            'committee' => Models\Committee::class,
            'committee_evaluation' => Models\CommitteeEvaluation::class,
            'committee_decision' => Models\CommitteeDecision::class,
            'checklist_template' => Models\ChecklistTemplate::class,
            'document_type' => Models\DocumentType::class,
            'document_requirement' => Models\DocumentRequirement::class,
            'email_template' => Models\EmailTemplate::class,
            'recruitment_cycle' => Models\RecruitmentCycle::class,
            'referee_form' => Models\RefereeFormTemplate::class,
            'evaluation_form' => Models\EvaluationFormTemplate::class,
            'scrutiny_team' => Models\ScrutinyTeam::class,
            'role' => Models\Role::class,
            'setting' => Models\Setting::class,
        ]);

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by(mb_strtolower((string) $r->input('email')).'|'.$r->ip()),
            Limit::perMinute(20)->by($r->ip()),
        ]);
        RateLimiter::for('register', fn (Request $r) => Limit::perHour(10)->by($r->ip()));
        RateLimiter::for('password', fn (Request $r) => Limit::perMinute(3)->by($r->ip()));
        RateLimiter::for('referee', fn (Request $r) => Limit::perMinute((int) app(SettingsService::class)->get('referee.token_rate_limit_per_minute', 30))->by($r->ip()));
        RateLimiter::for('referee-code', fn (Request $r) => Limit::perMinutes(10, 5)->by($r->ip().'|'.$r->route('token')));
        RateLimiter::for('uploads', fn (Request $r) => Limit::perMinute(30)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('submit', fn (Request $r) => Limit::perMinute(5)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('exports', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
    }
}
