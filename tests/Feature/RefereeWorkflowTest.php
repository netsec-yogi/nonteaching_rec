<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\RefereeInvitation;
use App\Models\RefereeResponse;
use App\Services\RefereeService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

class RefereeWorkflowTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
        $this->application = $this->submitted();
    }

    private function invitation(int $position = 1): RefereeInvitation
    {
        return RefereeInvitation::whereHas('referee', fn ($q) => $q->where('position', $position))
            ->where('application_id', $this->application->id)->where('state', 'active')->firstOrFail();
    }

    public function test_exactly_three_independent_invitations_with_hashed_tokens(): void
    {
        $invitations = RefereeInvitation::with('referee')->where('application_id', $this->application->id)->get();
        $this->assertCount(3, $invitations);
        $this->assertSame([1, 2, 3], $invitations->map(fn ($i) => $i->referee->position)->sort()->values()->all());

        foreach ($invitations as $invitation) {
            $raw = $this->rawToken($invitation);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $raw);
            $this->assertSame(hash('sha256', $raw), $invitation->token_hash);
            // The database column holding the raw token is encrypted at rest.
            $stored = \DB::table('referee_invitations')->where('id', $invitation->id)->value('token_encrypted');
            $this->assertStringNotContainsString($raw, $stored);
            $this->assertSame($this->application->advertisement_id, $invitation->advertisement_id);
            $this->assertTrue($invitation->expires_at->isFuture());
        }
    }

    public function test_valid_link_shows_only_the_referee_form(): void
    {
        $response = $this->getJson('/api/referee/'.$this->rawToken($this->invitation()))->assertOk()
            ->assertJsonPath('status', 'valid')
            ->assertJsonPath('applicant_name', 'RAVI KUMAR SHARMA')
            ->assertJsonPath('prefill.referee_email', 'referee1@example.org')
            ->assertJsonCount(10, 'form.questions');

        // Nothing from the application beyond name, post and advertisement number.
        $json = json_encode($response->json());
        foreach (['9876543210', 'SBIN226030012345', 'Shanti Nagar', 'referee2@example.org', 'suitability_statement', 'nine years', 'date_of_birth', $this->application->uuid] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "Referee view leaks [$leak]");
        }
        $this->assertSame(1, $this->invitation()->open_count);
    }

    public function test_invalid_tokens_are_rejected(): void
    {
        $raw = $this->rawToken($this->invitation());
        $this->getJson('/api/referee/'.substr($raw, 0, 63).'0')->assertNotFound()->assertJsonPath('status', 'invalid');
        $this->getJson('/api/referee/not-a-token')->assertNotFound();
        // The application UUID or reference number can never act as a token.
        $this->getJson('/api/referee/'.$this->application->uuid)->assertNotFound();
    }

    public function test_referee_submits_once_and_pdf_is_linked_to_the_right_application(): void
    {
        $invitation = $this->invitation(2);
        $raw = $this->rawToken($invitation);

        $this->postJson("/api/referee/$raw/submit", $this->refereeAnswers(['referee_name' => 'Shri M. L. Jain (corrected)']))
            ->assertCreated()->assertJsonPath('status', 'completed');

        $response = RefereeResponse::firstOrFail();
        $this->assertSame($invitation->id, $response->referee_invitation_id);
        $this->assertSame($this->application->id, $response->application_id);
        $this->assertSame($invitation->referee_id, $response->referee_id);
        $this->assertSame('Shri M. L. Jain (corrected)', $response->referee_name);
        $this->assertSame('Shri M. L. Jain', $response->candidate_entered['name'], 'Candidate-entered details are preserved separately');
        $this->assertSame('How would you assess the applicant’s integrity and character?', collect($response->answers)->firstWhere('code', 'integrity')['label']);
        $this->assertNotNull($response->pdf_path);
        Storage::disk('testing')->assertExists($response->pdf_path);

        $this->assertSame('completed', $invitation->fresh()->state);
        $this->assertNull($invitation->fresh()->token_encrypted, 'The resend copy of the token is wiped on completion');
        $this->assertSame('partial', $this->application->fresh()->referee_status);
        $this->assertDatabaseHas('email_logs', ['template_key' => 'referee_completed', 'to_email' => $this->admin->email]);

        // Replay: the same link cannot submit again, and the form now reports completion.
        $this->postJson("/api/referee/$raw/submit", $this->refereeAnswers())->assertStatus(409)->assertJsonPath('status', 'completed');
        $this->getJson("/api/referee/$raw")->assertStatus(410)->assertJsonPath('status', 'completed');
        $this->assertSame(1, RefereeResponse::count());
    }

    public function test_submission_is_validated_against_the_configured_questions(): void
    {
        $raw = $this->rawToken($this->invitation());
        $this->postJson("/api/referee/$raw/submit", $this->refereeAnswers([
            'answers' => ['competence' => 9, 'would_recommend' => 'perhaps'],
            'overall_recommendation' => 'Excellent!!',
            'confirm_good_faith' => false,
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'answers.competence', 'answers.would_recommend', 'answers.integrity', 'overall_recommendation', 'confirm_good_faith',
        ]);
        $this->assertSame(0, RefereeResponse::count());
    }

    public function test_expired_and_revoked_links_are_rejected(): void
    {
        $expired = $this->invitation(1);
        $expired->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->getJson('/api/referee/'.$this->rawToken($expired))->assertStatus(410)->assertJsonPath('status', 'expired');
        $this->postJson('/api/referee/'.$this->rawToken($expired).'/submit', $this->refereeAnswers())->assertStatus(409);

        $revoked = $this->invitation(2);
        $raw = $this->rawToken($revoked);
        app(RefereeService::class)->revoke($revoked, $this->admin, 'Referee asked to be replaced');
        $this->getJson("/api/referee/$raw")->assertStatus(410)->assertJsonPath('status', 'revoked');
    }

    public function test_one_referees_link_cannot_reach_another_referees_response(): void
    {
        $first = $this->invitation(1);
        $this->postJson('/api/referee/'.$this->rawToken($first).'/submit', $this->refereeAnswers())->assertCreated();

        $second = $this->rawToken($this->invitation(2));
        $view = $this->getJson("/api/referee/$second")->assertOk()->json();
        $this->assertSame('referee2@example.org', $view['prefill']['referee_email']);
        $this->assertStringNotContainsString('Good candidate.', json_encode($view));
    }

    public function test_administrator_manages_invitations_and_every_action_is_logged(): void
    {
        $inv = $this->invitation(3);
        $url = "/api/admin/applications/{$this->application->uuid}/invitations/{$inv->uuid}";
        $this->actingAs($this->admin);

        $this->postJson("$url/resend")->assertOk();
        $this->postJson("$url/remind")->assertOk();
        $this->assertSame(1, $inv->fresh()->reminder_count);
        $this->postJson("$url/extend", ['until' => now()->addDays(30)->toDateString()])->assertOk();
        $this->assertTrue($inv->fresh()->expires_at->isAfter(now()->addDays(29)));

        $oldRaw = $this->rawToken($inv);
        $this->postJson("$url/replace", ['reason' => 'Email bounced', 'email' => 'prof.mishra@jiwaji.example'])->assertOk()
            ->assertJsonPath('referees.2.email', 'prof.mishra@jiwaji.example');
        $this->getJson("/api/referee/$oldRaw")->assertStatus(410);

        $new = $this->invitation(3);
        $this->assertSame($inv->id, $new->replaces_id);
        $this->assertSame($inv->referee_id, $new->referee_id);
        $this->getJson('/api/referee/'.$this->rawToken($new))->assertOk();

        foreach (['referee.invitation_resent', 'referee.reminder_sent', 'referee.deadline_extended', 'referee.invitation_replaced'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action, 'application_id' => $this->application->id]);
        }
        $this->assertSame(1, $this->application->fresh()->refereeInvitations()->where('state', 'replaced')->count());
    }

    public function test_delivery_failure_is_recorded_separately_from_recommendation_status(): void
    {
        $inv = $this->invitation(2);
        app(RefereeService::class)->markDeliveryFailed($inv->id, '550 mailbox unavailable');

        $this->actingAs($this->admin);
        $row = collect($this->getJson("/api/admin/applications/{$this->application->uuid}")->assertOk()->json('referees'))->firstWhere('position', 2);
        $this->assertSame('failed', $row['delivery_status']);
        $this->assertSame('pending', $row['recommendation_status']);
        $this->assertDatabaseHas('email_logs', ['template_key' => 'referee_delivery_failed', 'to_email' => $this->admin->email]);
    }

    public function test_recommendations_are_visible_to_authorised_staff_but_never_the_applicant(): void
    {
        $this->postJson('/api/referee/'.$this->rawToken($this->invitation()).'/submit', $this->refereeAnswers())->assertCreated();
        $response = RefereeResponse::firstOrFail();
        $base = "/api/admin/applications/{$this->application->uuid}/recommendations/{$response->id}";

        // Recruitment administrator (assigned to the advertisement).
        $this->actingAs($this->admin)->getJson($base)->assertOk()->assertJsonPath('overall_recommendation', 'Recommended');
        $this->getJson("$base/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertDatabaseHas('document_access_logs', ['resource_type' => 'referee_pdf', 'resource_id' => $response->id, 'user_id' => $this->admin->id]);

        // Screening Committee member once the application is with their committee.
        $committee = $this->committee();
        $committee->assignments()->create(['application_id' => $this->application->id]);
        $this->actingAs($this->member)->getJson($base)->assertOk();

        // The applicant: no staff routes, and nothing in their own views.
        $this->actingAs($this->application->user)->getJson($base)->assertForbidden();
        $this->getJson("$base/pdf")->assertForbidden();
        $status = json_encode($this->getJson("/api/applications/{$this->application->uuid}/status")->assertOk()->json());
        $this->assertStringContainsString('"received":true', $status);
        $this->assertStringNotContainsString('Good candidate.', $status);
        $this->assertStringNotContainsString('Recommended', $status);

        // A scrutiny user without the permission sees status but not content.
        $this->team();
        $this->actingAs($this->scrutiny)->getJson($base)->assertForbidden();
        $this->getJson("/api/admin/applications/{$this->application->uuid}")->assertOk()->assertJsonPath('recommendations', null);
    }

    public function test_submitted_recommendations_are_immutable_and_amendments_are_recorded(): void
    {
        $this->postJson('/api/referee/'.$this->rawToken($this->invitation()).'/submit', $this->refereeAnswers())->assertCreated();
        $response = RefereeResponse::firstOrFail();

        try {
            $response->update(['overall_recommendation' => 'Not Recommended']);
            $this->fail('A submitted recommendation was overwritten.');
        } catch (LogicException) {
        }

        $this->actingAs($this->super)->postJson("/api/admin/applications/{$this->application->uuid}/recommendations/{$response->id}/amend", [
            'field' => 'referee_designation', 'amended_value' => 'Registrar (Officiating)', 'reason' => 'Referee wrote to correct designation',
        ])->assertCreated();

        $this->assertSame('Registrar', $response->fresh()->referee_designation);
        $this->assertDatabaseHas('referee_response_amendments', ['referee_response_id' => $response->id, 'original_value' => 'Registrar', 'amended_value' => 'Registrar (Officiating)']);

        // Amending the recommendation itself never copies its content into the audit log (readable with audit.view alone).
        $this->postJson("/api/admin/applications/{$this->application->uuid}/recommendations/{$response->id}/amend", [
            'field' => 'comments', 'amended_value' => 'Outstanding candidate, strongly recommended.', 'reason' => 'Referee sent a corrected letter',
        ])->assertCreated();
        $audit = AuditLog::where('action', 'referee.recommendation_amended')->latest('id')->first();
        $this->assertStringNotContainsString('candidate', json_encode([$audit->old_values, $audit->new_values, $audit->meta]));
        $this->assertSame('comments', $audit->meta['field']);
    }

    public function test_reminders_follow_the_configured_schedule(): void
    {
        $service = app(RefereeService::class);
        $inv = $this->invitation(2);
        $this->assertFalse($service->reminderDue($inv->load('application.advertisement')));

        $this->travel(5)->days();
        $this->assertTrue($service->reminderDue($inv->fresh()->load('application.advertisement')));
        $this->assertSame(3, $service->sendDueReminders()); // all three are still pending
        $this->assertSame(0, $service->sendDueReminders(), 'Never more than one reminder a day');
    }

    public function test_extra_verification_requires_an_emailed_code(): void
    {
        $this->ad->update(['referee_extra_verification' => true]);
        $inv = $this->invitation();
        $raw = $this->rawToken($inv);

        $this->getJson("/api/referee/$raw")->assertOk()->assertJsonPath('requires_verification', true)->assertJsonPath('verified', false);
        $this->postJson("/api/referee/$raw/submit", $this->refereeAnswers())->assertForbidden();

        $this->postJson("/api/referee/$raw/code")->assertOk();
        $this->assertDatabaseHas('email_logs', ['template_key' => 'referee_verification_code', 'to_email' => 'referee1@example.org']);
        $this->postJson("/api/referee/$raw/verify", ['code' => '000000'])->assertUnprocessable();
    }

    public function test_missing_recommendations_never_remove_an_application_and_waivers_are_recorded(): void
    {
        $this->assertSame('pending', $this->application->fresh()->referee_status);
        $this->actingAs($this->super)->postJson("/api/admin/applications/{$this->application->uuid}/referee-waiver", ['waive' => true, 'reason' => 'Referee deceased'])
            ->assertOk()->assertJsonPath('referee_status', 'exception');
        $this->assertSame(Application::SUBMITTED, $this->application->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'referee.requirement_waived']);
    }
}
