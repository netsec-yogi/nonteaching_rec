<?php

namespace App\Services;

use App\Models\Application;
use App\Models\Referee;
use App\Models\RefereeFormTemplate;
use App\Models\RefereeInvitation;
use App\Models\RefereeQuestion;
use App\Models\RefereeResponse;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffAlert;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The confidential referee recommendation workflow.
 *
 * Tokens: 32 random bytes, hex-encoded, sent only in the email. The database
 * holds the SHA-256 (used for lookup) and an APP_KEY-encrypted copy used only
 * to repeat the same link in reminders; that copy is wiped once the
 * invitation completes or is revoked. A token grants access to one
 * invitation's form and nothing else.
 */
class RefereeService
{
    public function __construct(
        private AuditLogger $audit,
        private TemplateMailer $mailer,
        private SettingsService $settings,
        private PdfService $pdf,
    ) {}

    // ------------------------------------------------------------ issuing

    /**
     * Ensure every referee of a submitted application has a live invitation.
     * Called inside the submission transaction; emails go out after commit.
     *
     * @return Collection<int, RefereeInvitation> invitations created now
     */
    public function inviteAll(Application $application, ?User $actor = null): Collection
    {
        $application->loadMissing(['referees.invitations', 'advertisement']);
        $created = collect();

        foreach ($application->referees as $referee) {
            $current = $referee->invitations->first(); // newest first
            if ($current && in_array($current->state, [RefereeInvitation::ACTIVE, RefereeInvitation::COMPLETED], true)) {
                if ($current->state === RefereeInvitation::ACTIVE && $current->email !== $referee->email) {
                    // The applicant corrected this referee's email after a reopening.
                    $created->push($this->replace($current, $actor, 'Referee details corrected by the applicant.'));
                }

                continue;
            }
            $created->push($this->issue($referee, $actor));
        }

        return $created;
    }

    public function issue(Referee $referee, ?User $actor = null, ?RefereeInvitation $replaces = null, ?string $email = null): RefereeInvitation
    {
        $referee->loadMissing('application.advertisement');
        $application = $referee->application;
        $raw = bin2hex(random_bytes((int) config('recruitment.referee.token_bytes', 32)));

        $invitation = RefereeInvitation::create([
            'application_id' => $application->id,
            'advertisement_id' => $application->advertisement_id,
            'referee_id' => $referee->id,
            'email' => $email ?? $referee->email,
            'token_hash' => RefereeInvitation::hashToken($raw),
            'token_encrypted' => $raw,
            'state' => RefereeInvitation::ACTIVE,
            'delivery_status' => 'not_sent',
            'expires_at' => now()->addDays($application->advertisement->referee_deadline_days)->endOfDay(),
            'replaces_id' => $replaces?->id,
            'created_by' => $actor?->id,
        ]);

        $this->audit->record('referee.invitation_created', $invitation, [], [
            'referee_position' => $referee->position,
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ], application: $application, user: $actor, actorType: 'system');

        $this->dispatch($invitation, $raw, 'referee_invitation');

        return $invitation;
    }

    public function resend(RefereeInvitation $invitation, User $actor): void
    {
        $this->assertActive($invitation);
        $this->dispatch($invitation, $invitation->token_encrypted, 'referee_invitation');
        $this->audit->record('referee.invitation_resent', $invitation, [], [], application: $invitation->application, user: $actor);
    }

    public function remind(RefereeInvitation $invitation, ?User $actor = null): void
    {
        $this->assertActive($invitation);
        $invitation->forceFill(['reminder_count' => $invitation->reminder_count + 1, 'last_reminder_at' => now()])->save();
        $this->dispatch($invitation, $invitation->token_encrypted, 'referee_reminder');
        $this->audit->record('referee.reminder_sent', $invitation, [], ['reminder_count' => $invitation->reminder_count],
            application: $invitation->application, user: $actor, actorType: 'system');
    }

    public function revoke(RefereeInvitation $invitation, User $actor, string $reason, string $state = RefereeInvitation::REVOKED): void
    {
        if ($invitation->state !== RefereeInvitation::ACTIVE) {
            throw ValidationException::withMessages(['invitation' => 'Only a pending invitation can be revoked.']);
        }
        $invitation->forceFill([
            'state' => $state,
            'revoked_at' => now(),
            'revoked_by' => $actor->id,
            'revocation_reason' => $reason,
            'token_encrypted' => null,
        ])->save();

        $this->audit->record("referee.invitation_{$state}", $invitation, ['state' => 'active'], ['state' => $state], ['reason' => $reason],
            application: $invitation->application, user: $actor);
        $this->refreshApplicationStatus($invitation->application);
    }

    /** Revoke (as replaced) and issue a fresh link for the same referee and application. */
    public function replace(RefereeInvitation $invitation, ?User $actor, string $reason, ?string $email = null): RefereeInvitation
    {
        return DB::transaction(function () use ($invitation, $actor, $reason, $email) {
            $invitation = RefereeInvitation::lockForUpdate()->findOrFail($invitation->id);
            if ($invitation->state === RefereeInvitation::COMPLETED) {
                throw ValidationException::withMessages(['invitation' => 'This referee has already submitted a recommendation.']);
            }
            if ($invitation->state === RefereeInvitation::ACTIVE) {
                $invitation->forceFill([
                    'state' => RefereeInvitation::REPLACED, 'revoked_at' => now(), 'revoked_by' => $actor?->id,
                    'revocation_reason' => $reason, 'token_encrypted' => null,
                ])->save();
            }
            $new = $this->issue($invitation->referee, $actor, $invitation, $email ?? $invitation->referee->email);
            $this->audit->record('referee.invitation_replaced', $new, ['replaced_invitation' => $invitation->uuid],
                ['email' => $new->email], ['reason' => $reason], application: $invitation->application, user: $actor);

            return $new;
        });
    }

    public function extend(RefereeInvitation $invitation, User $actor, \DateTimeInterface $until): void
    {
        if (! in_array($invitation->state, [RefereeInvitation::ACTIVE], true)) {
            throw ValidationException::withMessages(['invitation' => 'Only a pending invitation can be extended.']);
        }
        $old = $invitation->expires_at;
        $invitation->forceFill(['expires_at' => Carbon::instance($until)->endOfDay()])->save();
        $this->audit->record('referee.deadline_extended', $invitation, ['expires_at' => $old->toIso8601String()],
            ['expires_at' => $invitation->expires_at->toIso8601String()], application: $invitation->application, user: $actor);
    }

    /** Record that a missing recommendation will not hold the application back. */
    public function waiveRequirement(Application $application, User $actor, bool $waive, ?string $reason): void
    {
        $application->forceFill(['referee_requirement_waived' => $waive, 'referee_waiver_reason' => $waive ? $reason : null])->save();
        $this->audit->record($waive ? 'referee.requirement_waived' : 'referee.waiver_withdrawn', $application, [], [], ['reason' => $reason], user: $actor);
        $this->refreshApplicationStatus($application);
    }

    // ------------------------------------------------------------ delivery

    public function markDelivered(int $invitationId): void
    {
        RefereeInvitation::whereKey($invitationId)->update(['delivery_status' => 'sent', 'sent_at' => now(), 'delivery_error' => null]);
    }

    public function markDeliveryFailed(int $invitationId, string $error): void
    {
        $invitation = RefereeInvitation::with(['application.post', 'application.personal', 'referee'])->find($invitationId);
        if (! $invitation) {
            return;
        }
        $invitation->forceFill(['delivery_status' => 'failed', 'delivery_failed_at' => now(), 'delivery_error' => mb_substr($error, 0, 1000)])->save();
        $this->audit->record('referee.delivery_failed', $invitation, [], [], ['error' => mb_substr($error, 0, 300)],
            application: $invitation->application, actorType: 'system');

        foreach ($this->recruitmentStaff($invitation->application) as $admin) {
            $this->mailer->send('referee_delivery_failed', $admin->email, $admin->name, [
                'admin_name' => $admin->name,
                'applicant_name' => $invitation->application->personal?->full_name,
                'reference_no' => $invitation->application->reference_no,
                'referee_name' => $invitation->referee->name,
                'referee_email' => $invitation->email,
                'error' => mb_substr($error, 0, 200),
            ], $invitation->application, $invitation->application);
        }
        Notification::send($this->recruitmentStaff($invitation->application), new StaffAlert(
            'Referee invitation could not be delivered',
            "Invitation to {$invitation->referee->name} for {$invitation->application->reference_no} failed.",
            '/admin/applications/'.$invitation->application->uuid,
        ));
    }

    private function dispatch(RefereeInvitation $invitation, ?string $raw, string $template): void
    {
        if (! $raw) {
            throw ValidationException::withMessages(['invitation' => 'This invitation link is no longer available. Issue a replacement invitation instead.']);
        }
        $invitation->loadMissing(['application.personal', 'application.post', 'application.advertisement', 'referee']);
        $a = $invitation->application;

        $invitation->forceFill(['delivery_status' => 'queued', 'queued_at' => now()])->save();

        $log = $this->mailer->send($template, $invitation->email, $invitation->referee->name, [
            'referee_name' => $invitation->referee->name,
            'applicant_name' => $a->personal?->full_name,
            'post_name' => $a->post->name,
            'advertisement_number' => $a->advertisement->number,
            'reference_no' => $a->reference_no,
            'referee_link' => $this->link($raw),
            'deadline' => $invitation->expires_at->format('d M Y'),
        ], $invitation, $a);

        if (! $log) {
            $this->markDeliveryFailed($invitation->id, "Email template [$template] is missing or inactive.");
        }
    }

    public function link(string $raw): string
    {
        return rtrim(config('recruitment.frontend_url'), '/').'/referee/'.$raw;
    }

    private function assertActive(RefereeInvitation $invitation): void
    {
        if ($invitation->state !== RefereeInvitation::ACTIVE) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is no longer pending.']);
        }
        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation has expired. Extend the deadline first.']);
        }
    }

    // ------------------------------------------------------------ the referee's side

    /**
     * Look up an invitation by raw token. Returns [invitation|null, status]
     * where status is one of: valid, invalid, expired, revoked, completed.
     */
    public function resolve(string $raw): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $raw)) {
            return [null, 'invalid'];
        }
        $invitation = RefereeInvitation::with(['referee', 'application.advertisement', 'application.post', 'application.personal'])
            ->where('token_hash', RefereeInvitation::hashToken($raw))->first();

        if (! $invitation || $invitation->referee_id !== $invitation->referee?->id
            || $invitation->application_id !== $invitation->referee?->application_id) {
            return [null, 'invalid'];
        }

        return [$invitation, match (true) {
            $invitation->state === RefereeInvitation::COMPLETED => 'completed',
            in_array($invitation->state, [RefereeInvitation::REVOKED, RefereeInvitation::REPLACED], true) => 'revoked',
            $invitation->isExpired() => 'expired',
            default => 'valid',
        }];
    }

    public function recordOpen(RefereeInvitation $invitation): void
    {
        $invitation->forceFill([
            'first_opened_at' => $invitation->first_opened_at ?? now(),
            'last_opened_at' => now(),
            'open_count' => $invitation->open_count + 1,
        ])->save();
    }

    public function requiresVerification(RefereeInvitation $invitation): bool
    {
        return $invitation->application->advertisement->referee_extra_verification;
    }

    public function isVerified(RefereeInvitation $invitation): bool
    {
        return ! $this->requiresVerification($invitation)
            || session('referee_verified.'.$invitation->id) === $invitation->token_hash;
    }

    public function sendVerificationCode(RefereeInvitation $invitation): void
    {
        $code = (string) random_int(100000, 999999);
        $invitation->forceFill([
            'verification_code_hash' => hash('sha256', $invitation->token_hash.$code),
            'verification_code_expires_at' => now()->addMinutes((int) config('recruitment.referee.verification_code_ttl_minutes')),
            'verification_attempts' => 0,
        ])->save();

        $this->mailer->send('referee_verification_code', $invitation->email, $invitation->referee->name, [
            'referee_name' => $invitation->referee->name,
            'code' => $code,
            'minutes' => config('recruitment.referee.verification_code_ttl_minutes'),
        ], $invitation, $invitation->application);
    }

    public function verifyCode(RefereeInvitation $invitation, string $code): bool
    {
        if ($invitation->verification_attempts >= (int) config('recruitment.referee.verification_max_attempts')
            || ! $invitation->verification_code_hash
            || $invitation->verification_code_expires_at?->isPast()) {
            return false;
        }
        $invitation->increment('verification_attempts');

        if (! hash_equals($invitation->verification_code_hash, hash('sha256', $invitation->token_hash.$code))) {
            return false;
        }
        $invitation->forceFill(['verified_at' => now(), 'verification_code_hash' => null])->save();
        session()->put('referee_verified.'.$invitation->id, $invitation->token_hash);
        $this->audit->record('referee.email_verified', $invitation, [], [], application: $invitation->application,
            actorType: 'referee', actorLabel: $invitation->email);

        return true;
    }

    public function formTemplate(RefereeInvitation $invitation): RefereeFormTemplate
    {
        $template = $invitation->application->advertisement->refereeFormTemplate
            ?? RefereeFormTemplate::where('is_active', true)->orderBy('id')->first();

        abort_unless($template, 503, 'The recommendation form has not been configured.');

        return $template->load('questions');
    }

    /** Only what a referee needs: never the application itself. */
    public function publicView(RefereeInvitation $invitation): array
    {
        $a = $invitation->application;
        $r = $invitation->referee;
        $template = $this->formTemplate($invitation);

        return [
            'institute' => $this->settings->get('institute.name'),
            'institute_address' => $this->settings->get('institute.address'),
            'contact_email' => $a->advertisement->contact_email ?? $this->settings->get('institute.recruitment_email'),
            'advertisement_number' => $a->advertisement->number,
            'post_name' => $a->post->name,
            'applicant_name' => $a->personal?->full_name,
            'deadline' => $invitation->expires_at->toIso8601String(),
            'prefill' => [
                'referee_name' => $r->name,
                'referee_designation' => $r->designation,
                'referee_organization' => $r->organization,
                'referee_email' => $invitation->email,
                'referee_phone' => $r->phone,
                'relationship' => $r->relationship,
                'known_duration' => $r->years_known !== null ? $r->years_known.' years' : '',
            ],
            'form' => [
                'introduction' => $template->introduction,
                'good_faith_statement' => $template->good_faith_statement,
                'recommendation_options' => $template->recommendation_options,
                'questions' => $template->questions->map(fn (RefereeQuestion $q) => $q->only(['code', 'label', 'help_text', 'type', 'options', 'is_required']))->values(),
            ],
            'requires_verification' => $this->requiresVerification($invitation),
            'verified' => $this->isVerified($invitation),
        ];
    }

    public function submit(RefereeInvitation $invitation, array $input): RefereeResponse
    {
        $template = $this->formTemplate($invitation);
        $data = $this->validateSubmission($template, $input);

        $response = DB::transaction(function () use ($invitation, $template, $data) {
            /** @var RefereeInvitation $locked */
            $locked = RefereeInvitation::with(['referee', 'application'])->lockForUpdate()->findOrFail($invitation->id);

            if ($locked->state !== RefereeInvitation::ACTIVE || $locked->isExpired()) {
                throw ValidationException::withMessages(['form' => 'This invitation is no longer accepting a recommendation.']);
            }
            if (RefereeResponse::where('referee_invitation_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['form' => 'A recommendation has already been submitted using this invitation.']);
            }

            $referee = $locked->referee;
            $answers = $template->questions->map(fn (RefereeQuestion $q) => [
                'code' => $q->code,
                'label' => $q->label,
                'type' => $q->type,
                'options' => $q->options,
                'answer' => $data['answers'][$q->code] ?? null,
            ])->values()->all();

            $record = [
                'referee_invitation_id' => $locked->id,
                'application_id' => $locked->application_id,
                'referee_id' => $referee->id,
                'reference_no' => sprintf('%s/R%d/%d', $locked->application->reference_no ?? 'APP'.$locked->application_id, $referee->position, $locked->id),
                'referee_name' => $data['referee_name'],
                'referee_designation' => $data['referee_designation'],
                'referee_organization' => $data['referee_organization'],
                'referee_email' => $data['referee_email'],
                'referee_phone' => $data['referee_phone'] ?? null,
                'relationship' => $data['relationship'],
                'known_duration' => $data['known_duration'],
                'nature_of_association' => $data['nature_of_association'] ?? null,
                'candidate_entered' => $referee->only(['name', 'designation', 'organization', 'email', 'phone', 'address', 'relationship', 'years_known']),
                'answers' => $answers,
                'overall_recommendation' => $data['overall_recommendation'],
                'comments' => $data['comments'] ?? null,
                'form_template_id' => $template->id,
                'ip_address' => request()?->ip(),
                'user_agent' => mb_substr((string) request()?->userAgent(), 0, 500),
                'submitted_at' => now(),
            ];
            $record['content_hash'] = hash('sha256', json_encode([
                $record['reference_no'], $record['referee_name'], $record['relationship'], $answers,
                $record['overall_recommendation'], $record['comments'], $record['submitted_at']->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE));

            $response = RefereeResponse::create($record);

            $locked->forceFill(['state' => RefereeInvitation::COMPLETED, 'completed_at' => now(), 'token_encrypted' => null])->save();

            $this->audit->record('referee.recommendation_submitted', $response, [], [
                'reference_no' => $response->reference_no,
                'referee_position' => $referee->position,
            ], application: $locked->application, actorType: 'referee', actorLabel: $locked->email);

            $this->refreshApplicationStatus($locked->application);

            return $response;
        });

        $this->afterSubmission($response);

        return $response;
    }

    private function afterSubmission(RefereeResponse $response): void
    {
        try {
            $this->pdf->refereeRecommendation($response);
        } catch (Throwable $e) {
            // The response is saved; the PDF can be regenerated on demand.
            Log::error('Referee recommendation PDF failed', ['response' => $response->id, 'error' => $e->getMessage()]);
        }

        $response->loadMissing(['application.personal', 'application.post', 'application.advertisement', 'referee', 'invitation']);
        $a = $response->application;

        // Receipt to the referee: confirmation and reference only, never the content.
        $this->mailer->send('referee_submission_receipt', $response->invitation->email, $response->referee_name, [
            'referee_name' => $response->referee_name,
            'applicant_name' => $a->personal?->full_name,
            'post_name' => $a->post->name,
            'advertisement_number' => $a->advertisement->number,
            'submitted_at' => $response->submitted_at->format('d M Y, h:i A'),
            'recommendation_reference' => $response->reference_no,
        ], $response, $a);

        $staff = $this->recruitmentStaff($a);
        foreach ($staff as $admin) {
            $this->mailer->send('referee_completed', $admin->email, $admin->name, [
                'admin_name' => $admin->name,
                'applicant_name' => $a->personal?->full_name,
                'reference_no' => $a->reference_no,
                'post_name' => $a->post->name,
                'referee_name' => $response->referee_name,
                'referee_position' => $response->referee->position,
                'application_url' => rtrim(config('recruitment.frontend_url'), '/').'/admin/applications/'.$a->uuid,
            ], $response, $a);
        }
        Notification::send($staff, new StaffAlert(
            'Referee recommendation received',
            "Referee {$response->referee->position} ({$response->referee_name}) submitted a recommendation for {$a->reference_no}.",
            '/admin/applications/'.$a->uuid,
        ));
    }

    private function validateSubmission(RefereeFormTemplate $template, array $input): array
    {
        $rules = [
            'referee_name' => ['required', 'string', 'max:200'],
            'referee_designation' => ['required', 'string', 'max:200'],
            'referee_organization' => ['required', 'string', 'max:250'],
            'referee_email' => ['required', 'email:rfc', 'max:255'],
            'referee_phone' => ['nullable', 'string', 'max:20'],
            'relationship' => ['required', 'string', 'max:300'],
            'known_duration' => ['required', 'string', 'max:100'],
            'nature_of_association' => ['nullable', 'string', 'max:2000'],
            'answers' => ['array'],
            'overall_recommendation' => ['required', Rule::in($template->recommendation_options)],
            'comments' => ['nullable', 'string', 'max:10000'],
            'confirm_good_faith' => ['accepted'],
        ];
        $attributes = [];

        foreach ($template->questions as $q) {
            $key = "answers.{$q->code}";
            $base = $q->is_required ? ['required'] : ['nullable'];
            $rules[$key] = array_merge($base, match ($q->type) {
                'yes_no' => [Rule::in(['yes', 'no'])],
                'rating' => ['integer', 'between:'.($q->options['min'] ?? 1).','.($q->options['max'] ?? 5)],
                'choice' => [Rule::in($q->options['choices'] ?? [])],
                'short_text' => ['string', 'max:500'],
                default => ['string', 'max:5000'],
            });
            $attributes[$key] = '"'.mb_strimwidth($q->label, 0, 60, '…').'"';
        }

        return Validator::make($input, $rules, [
            'confirm_good_faith.accepted' => 'Please confirm that the information is accurate and submitted in good faith.',
        ], $attributes)->validate();
    }

    // ------------------------------------------------------------ status

    public function refreshApplicationStatus(Application $application): void
    {
        $application->refresh()->loadMissing('advertisement');
        $required = $application->advertisement->referees_required;
        $completed = RefereeResponse::where('application_id', $application->id)->distinct('referee_id')->count('referee_id');
        $invited = RefereeInvitation::where('application_id', $application->id)->exists();

        $status = match (true) {
            $completed >= $required => 'complete',
            $application->referee_requirement_waived => 'exception',
            $completed > 0 => 'partial',
            $invited => 'pending',
            default => 'not_started',
        };

        if ($status !== $application->referee_status) {
            app(StatusService::class)->set($application, 'referee_status', $status);
        }
    }

    /** One row per referee position for the staff dashboard. */
    public function statusTable(Application $application): array
    {
        $application->loadMissing(['referees.invitations', 'referees.response']);

        return $application->referees->map(function (Referee $r) {
            $inv = $r->invitations->first();

            return [
                'position' => $r->position,
                'referee_id' => $r->id,
                'name' => $r->name,
                'designation' => $r->designation,
                'email' => $inv?->email ?? $r->email,
                'invitation_uuid' => $inv?->uuid,
                'delivery_status' => $inv?->delivery_status ?? 'not_sent',
                'recommendation_status' => $r->response ? 'completed' : ($inv?->recommendationStatus() ?? 'not_invited'),
                'sent_at' => $inv?->sent_at?->toIso8601String(),
                'expires_at' => $inv?->expires_at?->toIso8601String(),
                'reminder_count' => $inv?->reminder_count ?? 0,
                'opened' => (bool) $inv?->first_opened_at,
                'delivery_error' => $inv?->delivery_error,
                'submitted_at' => $r->response?->submitted_at?->toIso8601String(),
                'response_id' => $r->response?->id,
                'response_reference' => $r->response?->reference_no,
                'history' => $r->invitations->map(fn (RefereeInvitation $i) => [
                    'uuid' => $i->uuid, 'email' => $i->email, 'state' => $i->state, 'delivery_status' => $i->delivery_status,
                    'created_at' => $i->created_at?->toIso8601String(), 'expires_at' => $i->expires_at?->toIso8601String(),
                    'revocation_reason' => $i->revocation_reason,
                ])->values(),
            ];
        })->values()->all();
    }

    /** Applicant view: whether each recommendation has been received. Never the content. */
    public function applicantStatus(Application $application): array
    {
        return collect($this->statusTable($application))->map(fn ($row) => [
            'position' => $row['position'],
            'name' => $row['name'],
            'received' => $row['recommendation_status'] === 'completed',
            'status' => match ($row['recommendation_status']) {
                'completed' => 'Received',
                'expired' => 'Not received (deadline passed)',
                'revoked', 'replaced' => 'Invitation withdrawn',
                'not_invited' => 'Not yet invited',
                default => 'Awaiting',
            },
        ])->all();
    }

    // ------------------------------------------------------------ scheduled reminders

    /** @return int reminders sent */
    public function sendDueReminders(): int
    {
        $sent = 0;
        RefereeInvitation::query()
            ->with(['application.advertisement', 'referee'])
            ->where('state', RefereeInvitation::ACTIVE)
            ->where('expires_at', '>', now())
            ->whereNotNull('token_encrypted')
            ->chunkById(200, function ($invitations) use (&$sent) {
                foreach ($invitations as $invitation) {
                    if ($this->reminderDue($invitation)) {
                        try {
                            $this->remind($invitation);
                            $sent++;
                        } catch (Throwable $e) {
                            Log::warning('Referee reminder skipped', ['invitation' => $invitation->id, 'error' => $e->getMessage()]);
                        }
                    }
                }
            });

        return $sent;
    }

    public function reminderDue(RefereeInvitation $invitation): bool
    {
        $ad = $invitation->application->advertisement;
        if ($invitation->reminder_count >= $ad->referee_max_reminders) {
            return false;
        }
        if ($invitation->last_reminder_at && $invitation->last_reminder_at->gt(now()->subDay())) {
            return false; // never more than one a day
        }

        $schedule = collect($ad->referee_reminder_days ?? [])->map(fn ($d) => (int) $d)->sort()->values();
        $next = $schedule->get($invitation->reminder_count);
        if ($next !== null && now()->gte($invitation->created_at->copy()->addDays($next))) {
            return true;
        }

        $before = $ad->referee_final_reminder_days_before;
        if ($before !== null && now()->gte($invitation->expires_at->copy()->subDays($before))) {
            $finalPoint = $invitation->expires_at->copy()->subDays($before);

            return ! $invitation->last_reminder_at || $invitation->last_reminder_at->lt($finalPoint);
        }

        return false;
    }

    /** @return Collection<int, User> */
    public function recruitmentStaff(Application $application): Collection
    {
        $assigned = User::query()->where('is_active', true)
            ->whereHas('managedAdvertisements', fn ($q) => $q->where('advertisements.id', $application->advertisement_id))
            ->whereHas('roles', fn ($q) => $q->where('slug', Role::RECRUITMENT_ADMIN))
            ->get();

        return $assigned->isNotEmpty() ? $assigned : User::query()->where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))->get();
    }
}
