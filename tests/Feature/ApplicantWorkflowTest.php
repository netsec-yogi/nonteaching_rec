<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Application;
use App\Models\ApplicationVersion;
use App\Models\EmailLog;
use App\Models\RefereeInvitation;
use App\Models\User;
use Database\Seeders\DemoData;
use Database\Seeders\SampleFiles;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ApplicantWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->recruitment();
        Mail::fake();
    }

    public function test_applicant_registers_and_verifies_email(): void
    {
        $this->fromSpa()->postJson('/api/auth/register', [
            'name' => 'Priya Verma', 'email' => 'Priya@Example.com', 'mobile' => '9876501234',
            'password' => 'Str0ng!Passw0rd', 'password_confirmation' => 'Str0ng!Passw0rd', 'accept_privacy' => true,
        ])->assertCreated()->assertJsonPath('user.email_verified', false)->assertJsonPath('user.roles.0', 'applicant');

        $user = User::where('email', 'priya@example.com')->firstOrFail();
        $this->assertDatabaseHas('email_logs', ['template_key' => 'email_verification', 'to_email' => 'priya@example.com', 'status' => 'sent']);
        Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo('priya@example.com'));

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_login_starts_a_session_and_logout_ends_it(): void
    {
        $user = $this->applicant();
        $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password@123'])
            ->assertOk()->assertJsonPath('user.email', $user->email);
        $this->fromSpa()->getJson('/api/auth/me')->assertJsonPath('user.id', $user->id);
        $this->fromSpa()->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest('web');
    }

    public function test_registration_rejects_weak_passwords_and_duplicate_emails(): void
    {
        $this->applicant('taken@example.com');
        $this->postJson('/api/auth/register', [
            'name' => 'X Y', 'email' => 'taken@example.com', 'mobile' => '123', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'mobile', 'password', 'accept_privacy']);
    }

    public function test_login_locks_the_account_after_repeated_failures(): void
    {
        $user = $this->applicant();
        for ($i = 0; $i < 5; $i++) {
            $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->assertNotNull($user->fresh()->locked_until);
        $this->fromSpa()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password@123'])
            ->assertStatus(429); // throttled by the per-account rate limiter as well
    }

    public function test_full_application_can_be_saved_section_by_section_and_submitted(): void
    {
        $user = $this->applicant();
        $this->actingAs($user);

        $uuid = $this->postJson('/api/applications', ['post_id' => $this->post->id])->assertCreated()->json('uuid');
        $application = Application::where('uuid', $uuid)->firstOrFail();

        // A fresh draft is not ready: every step reports what is missing.
        $payload = $this->getJson("/api/applications/$uuid")->assertOk()->json();
        $this->assertFalse($payload['ready_to_submit']);
        $this->assertCount(17, $payload['steps']);
        $this->postJson("/api/applications/$uuid/submit", ['confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('application');

        // Fill through the same services the API uses, then check readiness over HTTP.
        DemoData::fill($application, true);
        $payload = $this->getJson("/api/applications/$uuid")->assertOk()->json();
        $this->assertSame([], collect($payload['steps'])->pluck('issues')->flatten()->all());
        $this->assertTrue($payload['ready_to_submit']);

        $this->postJson("/api/applications/$uuid/submit", ['confirm' => true])
            ->assertOk()
            ->assertJsonPath('version', 1)
            ->assertJsonPath('reference_no', 'IIITM/NT/26-03/00001');

        $application->refresh();
        $this->assertSame(Application::SUBMITTED, $application->status);
        $this->assertSame('submitted', $application->scrutiny_status);
        $this->assertSame('pending_verification', $application->fee_status);
        $this->assertTrue($application->documents()->where('is_frozen', false)->doesntExist());

        // Frozen snapshot and stored PDF.
        $version = ApplicationVersion::where('application_id', $application->id)->firstOrFail();
        $this->assertSame('RAVI KUMAR SHARMA', $version->snapshot['personal']['full_name']);
        $this->assertNotNull($version->pdf_path);
        Storage::disk('testing')->assertExists($version->pdf_path);
        $this->assertSame(hash('sha256', Storage::disk('testing')->get($version->pdf_path)), $version->pdf_sha256);

        // Confirmation email with the PDF attached.
        $this->assertDatabaseHas('email_logs', ['template_key' => 'application_submitted', 'to_email' => $user->email, 'status' => 'sent']);
        Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo($user->email) && count($m->attachments()) === 1);

        // Three independent invitations, each emailed.
        $invitations = RefereeInvitation::where('application_id', $application->id)->get();
        $this->assertCount(3, $invitations);
        $this->assertCount(3, $invitations->pluck('token_hash')->unique());
        $this->assertSame(3, EmailLog::where('template_key', 'referee_invitation')->where('status', 'sent')->count());
        $this->assertTrue($invitations->every(fn ($i) => $i->delivery_status === 'sent'));
    }

    public function test_submitted_application_is_locked_against_editing(): void
    {
        $application = $this->submitted();
        $this->actingAs($application->user);

        $this->putJson("/api/applications/{$application->uuid}/sections/suitability", ['suitability_statement' => 'Changed'])->assertForbidden();
        $this->postJson("/api/applications/{$application->uuid}/documents", [
            'file' => SampleFiles::pdf(), 'document_type' => 'supporting_sheet',
        ])->assertForbidden();
        $this->postJson("/api/applications/{$application->uuid}/submit", ['confirm' => true])->assertForbidden();

        $this->getJson("/api/applications/{$application->uuid}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson("/api/applications/{$application->uuid}/receipt")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_unverified_email_blocks_final_submission(): void
    {
        $application = $this->completedDraft();
        $application->user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($application->user->fresh())
            ->postJson("/api/applications/{$application->uuid}/submit", ['confirm' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_cannot_apply_twice_or_to_a_closed_post(): void
    {
        $user = $this->applicant();
        $this->actingAs($user)->postJson('/api/applications', ['post_id' => $this->post->id])->assertCreated();
        $this->postJson('/api/applications', ['post_id' => $this->post->id])->assertUnprocessable()->assertJsonValidationErrors('post_id');

        $this->ad->forceFill(['closes_at' => now()->subMinute()])->save();
        $other = $this->ad->posts()->where('code', 'ASST')->first();
        $this->postJson('/api/applications', ['post_id' => $other->id])->assertUnprocessable();
    }

    public function test_submission_after_the_deadline_is_refused(): void
    {
        $application = $this->completedDraft();
        $this->ad->forceFill(['closes_at' => now()->subMinute()])->save();

        $this->actingAs($application->user)->postJson("/api/applications/{$application->uuid}/submit", ['confirm' => true])
            ->assertUnprocessable()->assertJsonPath('errors.application.0', 'Applications for this post are closed.');
    }

    public function test_draft_is_saved_and_resumed(): void
    {
        $user = $this->applicant();
        $this->actingAs($user);
        $uuid = $this->postJson('/api/applications', ['post_id' => $this->post->id])->json('uuid');

        $this->putJson("/api/applications/$uuid/sections/suitability", ['suitability_statement' => 'I have nine years of experience.'])->assertOk();
        $this->getJson("/api/applications/$uuid")->assertJsonPath('data.suitability.suitability_statement', 'I have nine years of experience.');
        $this->getJson('/api/applicant/dashboard')->assertOk()->assertJsonPath('applications.0.status', 'draft');
    }

    public function test_reopened_application_creates_a_new_version_and_keeps_the_old_one(): void
    {
        $application = $this->submitted();
        $this->actingAs($this->super)->postJson("/api/admin/applications/{$application->uuid}/reopen", [
            'reason' => 'Correct the name of the second referee.', 'sections' => ['referees'],
        ])->assertOk()->assertJsonPath('status', 'reopened');

        $this->actingAs($application->user);
        // Only the reopened section may be edited.
        $this->putJson("/api/applications/{$application->uuid}/sections/suitability", ['suitability_statement' => 'x'])->assertForbidden();

        $data = $this->getJson("/api/applications/{$application->uuid}")->assertOk()->json('data.referees');
        $data['referees'][1]['email'] = 'new.referee2@example.org';
        $data['confirm_accuracy'] = true;
        $this->putJson("/api/applications/{$application->uuid}/sections/referees", $data)->assertOk();
        $this->postJson("/api/applications/{$application->uuid}/submit", ['confirm' => true])->assertOk()->assertJsonPath('version', 2);

        $this->assertSame(2, ApplicationVersion::where('application_id', $application->id)->count());
        $this->assertSame('referee2@example.org', ApplicationVersion::where('application_id', $application->id)->where('version', 1)->first()->snapshot['referees'][1]['email']);

        // The changed referee got a replacement invitation; the old link is dead.
        $ref2 = $application->referees()->where('position', 2)->first();
        $this->assertSame(['active', 'replaced'], $ref2->invitations()->orderByDesc('id')->pluck('state')->all());
        $this->assertSame('new.referee2@example.org', $ref2->invitations()->orderByDesc('id')->first()->email);
    }

    public function test_withdrawal_revokes_pending_referee_links(): void
    {
        $application = $this->submitted();
        $this->actingAs($application->user)->postJson("/api/applications/{$application->uuid}/withdraw", ['confirm' => true, 'reason' => 'Accepted another offer'])->assertOk();

        $this->assertSame(Application::WITHDRAWN, $application->fresh()->status);
        $this->assertSame(0, RefereeInvitation::where('application_id', $application->id)->where('state', 'active')->count());
    }
}
