<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Advertisement;
use App\Models\Application;
use App\Models\EmailLog;
use App\Models\RefereeInvitation;
use App\Models\RefereeResponse;
use App\Services\RetentionService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class RetentionAndSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
    }

    public function test_configured_defaults_are_served_and_used_as_fallbacks(): void
    {
        app(SettingsService::class)->set('referee.default_deadline_days', 21);
        app(SettingsService::class)->set('uploads.max_kb_default', 3072);

        $this->actingAs($this->super)->getJson('/api/admin/config-defaults')
            ->assertOk()->assertJson(['referee_deadline_days' => 21, 'upload_max_kb' => 3072, 'retention_years' => 5]);

        $this->postJson('/api/admin/document-types', ['code' => 'pan_card', 'name' => 'PAN card', 'allowed_mimes' => ['application/pdf']])
            ->assertCreated()->assertJsonPath('max_kb', 3072);

        $payload = collect($this->ad->only(['recruitment_cycle_id', 'title', 'payment_methods', 'referees_required', 'referee_max_reminders', 'endorsement_mode', 'committee_evaluation_mode']))
            ->merge(['number' => 'IIITM/NT/2026/09', 'reference_prefix' => 'NT-09', 'opens_at' => now()->toDateTimeString(), 'closes_at' => now()->addMonth()->toDateTimeString()])->all();
        $this->postJson('/api/admin/advertisements', $payload)->assertCreated()->assertJsonPath('referee_deadline_days', 21);
    }

    public function test_referee_receives_a_receipt_without_the_recommendation_content(): void
    {
        $application = $this->submitted();
        $invitation = RefereeInvitation::where('application_id', $application->id)->orderBy('id')->first();
        $this->postJson('/api/referee/'.$this->rawToken($invitation).'/submit', $this->refereeAnswers(['comments' => 'Very private remark']))->assertCreated();

        $log = EmailLog::where('template_key', 'referee_submission_receipt')->firstOrFail();
        $this->assertSame($invitation->email, $log->to_email);
        $this->assertSame('sent', $log->status);
        Mail::assertSent(TemplatedMail::class, fn ($m) => $m->hasTo($invitation->email)
            && str_contains($m->htmlBody, RefereeResponse::first()->reference_no)
            && ! str_contains($m->htmlBody, 'Very private remark'));
    }

    public function test_review_archives_and_flags_only_advertisements_past_retention(): void
    {
        $this->ad->forceFill(['status' => Advertisement::CLOSED, 'closes_at' => now()->subYears(6), 'retention_years' => null])->save();
        $fresh = $this->ad->replicate(['number', 'reference_prefix'])->fill(['number' => 'IIITM/NT/2025/01', 'reference_prefix' => 'NT-25-01', 'closes_at' => now()->subYear()]);
        $fresh->save();

        $due = app(RetentionService::class)->review();

        $this->assertSame([$this->ad->id], $due->pluck('id')->all());
        $this->assertSame(Advertisement::ARCHIVED, $this->ad->fresh()->status);
        $this->assertNotNull($this->ad->fresh()->retention_due_at);
        $this->assertNull($fresh->fresh()->retention_due_at);
        $this->assertDatabaseHas('email_logs', ['template_key' => 'retention_due', 'to_email' => $this->super->email]);
        $this->assertSame(0, app(RetentionService::class)->review()->count(), 'Flagging happens once');
    }

    public function test_disposal_refuses_until_due_then_removes_personal_data_but_keeps_the_outcome(): void
    {
        $application = $this->submitted();
        $invitation = RefereeInvitation::where('application_id', $application->id)->orderBy('id')->first();
        $this->postJson('/api/referee/'.$this->rawToken($invitation).'/submit', $this->refereeAnswers())->assertCreated();
        $application->forceFill(['outcome' => 'recommended'])->save();
        Storage::disk('testing')->assertExists('applications/'.$application->uuid);

        $service = app(RetentionService::class);
        try {
            $service->dispose($this->ad, 'Registrar');
            $this->fail('Disposal ran before the retention period ended.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not archived and due', $e->getMessage());
        }

        $this->ad->forceFill(['status' => Advertisement::CLOSED, 'closes_at' => now()->subYears(6)])->save();
        $service->review();
        $counts = $service->dispose($this->ad->fresh(), 'Dr. X, Registrar (order 12/2032)', $this->super);

        $this->assertSame(1, $counts['applications']);
        Storage::disk('testing')->assertMissing('applications/'.$application->uuid);
        $a = Application::find($application->id);
        $this->assertSame('IIITM/NT/26-03/00001', $a->reference_no, 'Reference number kept');
        $this->assertSame('recommended', $a->outcome, 'Outcome kept');
        $this->assertNull($a->suitability_statement);
        $this->assertSame('[disposed]', $a->personal()->value('full_name'));
        $this->assertSame('OBC', $a->personal()->value('category'), 'Statistics kept');
        $this->assertSame(0, $a->qualifications()->count());
        $this->assertSame(0, \DB::table('application_documents')->where('application_id', $a->id)->count());
        $this->assertSame('[disposed]', $a->referees()->value('email'));
        $this->assertSame([], RefereeResponse::first()->answers);
        $this->assertTrue($a->versions()->first()->snapshot['disposed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'retention.disposed', 'auditable_id' => $this->ad->id]);
        $this->assertNotNull($this->ad->fresh()->disposed_at);

        $this->expectException(RuntimeException::class); // a second disposal is refused
        $service->dispose($this->ad->fresh(), 'Registrar');
    }

    public function test_dispose_command_requires_confirmation_and_authority(): void
    {
        $this->artisan('recruitment:dispose', ['number' => $this->ad->number])->assertFailed();
        $this->artisan('recruitment:dispose', ['number' => $this->ad->number, '--confirm' => $this->ad->number])->assertFailed();
        $this->artisan('recruitment:dispose', ['number' => $this->ad->number, '--confirm' => $this->ad->number, '--authorised-by' => 'Registrar'])
            ->expectsOutputToContain('not archived and due')->assertFailed();
    }
}
