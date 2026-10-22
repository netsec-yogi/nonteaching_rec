<?php

namespace App\Services;

use App\Models\Advertisement;
use App\Models\Application;
use App\Models\ApplicationReopening;
use App\Models\ApplicationVersion;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\ApplicationForm\ApplicationFormService;
use App\Services\ApplicationForm\SnapshotBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Final submission, administrative reopening and withdrawal. Each submission
 * freezes a new ApplicationVersion (snapshot + PDF); earlier versions remain.
 */
class SubmissionService
{
    public function __construct(
        private ApplicationFormService $form,
        private SnapshotBuilder $snapshots,
        private RequirementResolver $requirements,
        private RefereeService $referees,
        private StatusService $status,
        private PdfService $pdf,
        private TemplateMailer $mailer,
        private AuditLogger $audit,
    ) {}

    public function submit(Application $application, User $user): Application
    {
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => 'Verify your email address before submitting the application.']);
        }

        $version = DB::transaction(function () use ($application, $user) {
            /** @var Application $app */
            $app = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();

            if (! $app->isEditableByApplicant()) {
                throw ValidationException::withMessages(['application' => 'This application has already been submitted.']);
            }

            $resubmission = $app->status === Application::REOPENED;
            $advertisement = Advertisement::whereKey($app->advertisement_id)->lockForUpdate()->firstOrFail();

            if (! $resubmission && (! $advertisement->isOpen() || $app->post()->value('status') !== 'open')) {
                throw ValidationException::withMessages(['application' => 'Applications for this post are closed.']);
            }

            $issues = collect($this->form->issues($app))->flatten();
            if ($issues->isNotEmpty()) {
                throw ValidationException::withMessages(['application' => $issues->values()->all()]);
            }

            if (! $app->reference_no) {
                $advertisement->increment('reference_sequence');
                $app->reference_no = sprintf('%s/%05d', $advertisement->reference_prefix, $advertisement->reference_sequence);
            }

            $now = now();
            $app->forceFill([
                'version' => $app->version + 1,
                'submitted_at' => $now,
                'first_submitted_at' => $app->first_submitted_at ?? $now,
                'application_date' => $app->application_date ?? $now->toDateString(),
                'reopened_until' => null,
            ])->save();

            $this->status->set($app, 'status', Application::SUBMITTED, $resubmission ? 'Resubmitted after correction' : 'Final submission');
            if (! $app->scrutiny_status) {
                $this->status->set($app, 'scrutiny_status', 'submitted');
            }

            $payment = $app->payment()->first();
            $this->status->set($app, 'fee_status', $payment?->status === 'exempt' ? 'exempt' : ($payment?->status === 'verified' ? 'verified' : 'pending_verification'));

            $app->load('post.advertisement');
            if ($this->requirements->endorsementApplicable($app)) {
                $this->status->set($app, 'endorsement_status', $app->endorsement_status === 'verified' ? 'verified' : 'submitted');
            } else {
                $this->status->set($app, 'endorsement_status', 'not_applicable');
            }

            $app->documents()->update(['is_frozen' => true]);

            $version = ApplicationVersion::create([
                'application_id' => $app->id,
                'version' => $app->version,
                'snapshot' => $this->snapshots->build($app->fresh()),
                'reason' => $resubmission ? 'Resubmission after authorised correction' : 'Final submission',
                'created_by' => $user->id,
            ]);

            if ($resubmission) {
                ApplicationReopening::where('application_id', $app->id)->whereNull('resubmitted_at')->update(['resubmitted_at' => $now]);
            }

            $this->referees->inviteAll($app, $user);
            $this->referees->refreshApplicationStatus($app);

            $this->audit->record($resubmission ? 'application.resubmitted' : 'application.submitted', $app, [], [
                'reference_no' => $app->reference_no,
                'version' => $app->version,
            ]);

            return $version;
        });

        $application->refresh();
        $this->afterSubmission($application, $version);

        return $application;
    }

    private function afterSubmission(Application $application, ApplicationVersion $version): void
    {
        try {
            $this->pdf->applicationVersion($version);
        } catch (Throwable $e) {
            Log::error('Application PDF generation failed', ['application' => $application->id, 'error' => $e->getMessage()]);
        }
        $version->refresh();

        $application->loadMissing(['personal', 'post', 'advertisement', 'user']);
        $attachments = $version->pdf_path ? [[
            'disk' => $version->pdf_disk, 'path' => $version->pdf_path,
            'name' => 'Application-'.str_replace('/', '-', $application->reference_no).'.pdf', 'mime' => 'application/pdf',
        ]] : [];

        $this->mailer->send('application_submitted', $application->user->email, $application->personal?->full_name, [
            'applicant_name' => $application->personal?->full_name,
            'reference_no' => $application->reference_no,
            'post_name' => $application->post->name,
            'advertisement_number' => $application->advertisement->number,
            'submitted_at' => $application->submitted_at->format('d M Y, h:i A'),
            'version' => $application->version,
            'dashboard_url' => rtrim(config('recruitment.frontend_url'), '/').'/applications/'.$application->uuid,
        ], $application, $application, $attachments);

        Notification::send($this->referees->recruitmentStaff($application), new StaffAlert(
            $application->version > 1 ? 'Application resubmitted' : 'New application submitted',
            "{$application->reference_no} — {$application->personal?->full_name} for {$application->post->name}.",
            '/admin/applications/'.$application->uuid,
        ));
    }

    /**
     * Authorised reopening. The submitted version stays frozen; the candidate
     * edits the live record and a new version is created on resubmission.
     *
     * @param  list<string>|null  $sections  limit editing to these wizard steps
     */
    public function reopen(Application $application, User $actor, string $reason, ?\DateTimeInterface $until, ?array $sections): void
    {
        DB::transaction(function () use ($application, $actor, $reason, $until, $sections) {
            $app = Application::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($app->status !== Application::SUBMITTED) {
                throw ValidationException::withMessages(['application' => 'Only a submitted application can be reopened.']);
            }

            ApplicationReopening::create([
                'application_id' => $app->id,
                'reopened_by' => $actor->id,
                'reason' => $reason,
                'sections' => $sections ?: null,
                'from_version' => $app->version,
                'reopened_until' => $until,
            ]);
            $app->forceFill(['reopened_until' => $until])->save();
            // Documents already submitted stay frozen: earlier versions reference them by id
            // and hash. The candidate can add corrected documents alongside them.

            $this->status->set($app, 'status', Application::REOPENED, $reason);
            $this->audit->record('application.reopened', $app, [], ['until' => $until?->format('c'), 'sections' => $sections], ['reason' => $reason], user: $actor);
        });

        $application->refresh()->loadMissing(['user', 'personal']);
        $this->mailer->send('application_reopened', $application->user->email, $application->personal?->full_name, [
            'applicant_name' => $application->personal?->full_name,
            'reference_no' => $application->reference_no,
            'reason' => $reason,
            'until' => $until ? Carbon::instance($until)->format('d M Y, h:i A') : 'further notice',
            'dashboard_url' => rtrim(config('recruitment.frontend_url'), '/').'/applications/'.$application->uuid,
        ], $application, $application);
    }

    public function withdraw(Application $application, User $user, ?string $reason): void
    {
        $application->loadMissing('advertisement');
        if (! $application->advertisement->allow_withdrawal) {
            throw ValidationException::withMessages(['application' => 'Withdrawal is not permitted under this advertisement.']);
        }
        if ($application->status === Application::WITHDRAWN) {
            return;
        }
        if ($application->outcome !== null || $application->committee_status !== null) {
            throw ValidationException::withMessages(['application' => 'This application can no longer be withdrawn online. Contact the Recruitment Section.']);
        }

        DB::transaction(function () use ($application, $reason) {
            $application->forceFill(['withdrawn_at' => now(), 'withdrawal_reason' => $reason])->save();
            $this->status->set($application, 'status', Application::WITHDRAWN, $reason);
            $application->refereeInvitations()->where('state', 'active')->update([
                'state' => 'revoked', 'revoked_at' => now(), 'revocation_reason' => 'Application withdrawn', 'token_encrypted' => null,
            ]);
        });
    }
}
