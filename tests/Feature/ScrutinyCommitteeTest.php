<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ChecklistItem;
use App\Models\ScrutinyStage;
use App\Services\RequirementResolver;
use Database\Seeders\SampleFiles;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ScrutinyCommitteeTest extends TestCase
{
    private Application $application;

    private ScrutinyStage $initial;

    private ScrutinyStage $review;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
        $this->application = $this->submitted();
        $this->initial = $this->ad->scrutinyStages()->where('code', 'initial_scrutiny')->first();
        $this->review = $this->ad->scrutinyStages()->where('code', 'review')->first();
    }

    private function url(string $path = ''): string
    {
        return "/api/admin/applications/{$this->application->uuid}".$path;
    }

    private function verifyAll(string $status = 'verified'): void
    {
        foreach (app(RequirementResolver::class)->checklistItems($this->application) as $item) {
            $this->putJson($this->url("/scrutiny/items/{$item->id}"), ['status' => $status, 'remarks' => 'Checked'])->assertOk();
        }
    }

    public function test_full_scrutiny_workflow_with_separation_of_duties(): void
    {
        $this->actingAs($this->admin)->postJson($this->url('/received'))->assertOk()->assertJsonPath('scrutiny_status', 'received');
        $this->assertDatabaseHas('email_logs', ['template_key' => 'application_acknowledgment']);
        $this->postJson($this->url('/assign'), ['user_id' => $this->scrutiny->id, 'stage_id' => $this->initial->id])->assertOk();
        $this->assertDatabaseHas('email_logs', ['template_key' => 'scrutiny_assigned', 'to_email' => $this->scrutiny->email]);

        // The scrutiny officer cannot report until mandatory items have verdicts.
        $this->actingAs($this->scrutiny);
        $this->postJson($this->url('/scrutiny/review'), ['completeness' => 'complete', 'recommendation' => 'further_consideration', 'confirm' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('checklist');

        $item = ChecklistItem::where('code', 'fee_receipt')->first();
        $this->putJson($this->url("/scrutiny/items/{$item->id}"), ['status' => 'not_verified'])
            ->assertUnprocessable()->assertJsonValidationErrors('discrepancy_reason');

        $this->verifyAll();
        $this->postJson($this->url('/scrutiny/observations'), ['observation' => 'Experience certificate from 2015 is a photocopy.', 'section' => 'employment'])->assertCreated();
        $this->postJson($this->url('/scrutiny/review'), ['completeness' => 'complete', 'recommendation' => 'further_consideration', 'reasons' => 'Eligible', 'confirm' => true])
            ->assertOk()->assertJsonPath('scrutiny_status', 'completed');
        $this->assertSame($this->review->id, $this->application->fresh()->current_stage_id);

        // The same officer cannot be assigned to approve their own work at the next stage.
        $this->actingAs($this->admin)->postJson($this->url('/assign'), ['user_id' => $this->scrutiny->id, 'stage_id' => $this->review->id])
            ->assertUnprocessable();

        $this->postJson($this->url('/assign'), ['user_id' => $this->admin->id, 'stage_id' => $this->review->id])->assertOk();
        $this->postJson($this->url('/scrutiny/review'), ['decision' => 'not_recommended', 'confirm' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('reasons');
        $this->postJson($this->url('/scrutiny/review'), ['decision' => 'recommended', 'confirm' => true])
            ->assertOk()->assertJsonPath('outcome', 'recommended');

        $report = $this->getJson($this->url('/scrutiny/report'))->assertOk()->json();
        $this->assertCount(2, $report['reviews']);
        $this->getJson($this->url('/scrutiny-report.pdf'))->assertOk()->assertHeader('content-type', 'application/pdf');

        // The original application data is untouched by scrutiny.
        $this->assertSame(1, $this->application->fresh()->version);
    }

    public function test_decision_stage_can_return_the_application(): void
    {
        $this->actingAs($this->admin)->postJson($this->url('/assign'), ['user_id' => $this->scrutiny->id, 'stage_id' => $this->initial->id]);
        $this->actingAs($this->scrutiny);
        $this->verifyAll();
        $this->postJson($this->url('/scrutiny/review'), ['completeness' => 'complete', 'recommendation' => 'further_consideration', 'confirm' => true])->assertOk();
        $this->actingAs($this->admin)->postJson($this->url('/assign'), ['user_id' => $this->admin->id, 'stage_id' => $this->review->id]);
        $this->postJson($this->url('/scrutiny/review'), ['decision' => 'returned', 'reasons' => 'Recheck the category certificate', 'confirm' => true])->assertOk();

        $fresh = $this->application->fresh();
        $this->assertSame($this->initial->id, $fresh->current_stage_id);
        $this->assertNull($fresh->outcome);
        $this->assertSame('in_progress', $this->application->scrutinyAssignments()->where('user_id', $this->scrutiny->id)->value('status'));
    }

    public function test_clarification_round_trip_does_not_alter_the_submitted_application(): void
    {
        $this->actingAs($this->admin)->postJson($this->url('/scrutiny/clarifications'), [
            'subject' => 'OBC certificate validity', 'message' => 'Upload an OBC-NCL certificate issued after 1 April 2026.', 'allow_uploads' => true,
        ])->assertCreated();
        $this->assertSame('clarification_required', $this->application->fresh()->scrutiny_status);
        $this->assertDatabaseHas('email_logs', ['template_key' => 'clarification_requested', 'to_email' => $this->application->user->email]);

        $request = $this->application->clarificationRequests()->first();
        $this->actingAs($this->application->user)->post("/api/applications/{$this->application->uuid}/clarifications/{$request->id}", [
            'message' => 'Fresh certificate attached.',
            'files' => [['file' => SampleFiles::pdf('obc.pdf'), 'document_type' => 'clarification_document']],
        ])->assertOk();

        $this->assertSame('clarification_received', $this->application->fresh()->scrutiny_status);
        $doc = $this->application->documents()->whereNotNull('clarification_response_id')->first();
        $this->assertNotNull($doc);
        $this->assertTrue($doc->is_frozen);
        $this->assertSame(1, $this->application->fresh()->version, 'A clarification never creates a new version of the application');

        $this->actingAs($this->admin)->postJson($this->url("/scrutiny/clarifications/{$request->id}/resolve"), ['status' => 'resolved', 'remarks' => 'Valid'])->assertOk();
        $this->assertSame('in_progress', $this->application->fresh()->scrutiny_status);
    }

    public function test_fee_and_endorsement_verification_is_recorded_by_staff(): void
    {
        $this->actingAs($this->admin)->postJson($this->url('/payment'), ['status' => 'rejected'])->assertUnprocessable();
        $this->postJson($this->url('/payment'), ['status' => 'verified', 'remarks' => 'Matched bank statement'])->assertOk()->assertJsonPath('fee_status', 'verified');
        $this->assertSame(1, $this->application->payment->verifications()->count());
        $this->postJson($this->url('/endorsement'), ['status' => 'verified'])->assertOk()->assertJsonPath('endorsement_status', 'verified');
    }

    public function test_committee_evaluates_and_decides_without_touching_the_application(): void
    {
        $committee = $this->committee();
        $this->application->forceFill(['outcome' => 'recommended'])->save();

        $this->actingAs($this->admin)->postJson('/api/admin/applications/forward', [
            'application_uuids' => [$this->application->uuid], 'committee_id' => $committee->id,
        ])->assertOk()->assertJsonPath('forwarded', 1);
        $this->assertSame('pending', $this->application->fresh()->committee_status);

        $this->actingAs($this->member);
        $this->getJson($this->url())->assertOk();
        $this->getJson($this->url('/committee'))->assertOk()->assertJsonPath('form.mode', 'individual');
        $responses = ['eligibility' => 'Eligible', 'essential_qualification' => 'yes', 'experience' => 4];
        $this->postJson($this->url('/committee/evaluation'), ['responses' => $responses, 'recommendation' => 'Recommended', 'submit' => true])->assertOk();
        $this->postJson($this->url('/committee/evaluation'), ['responses' => $responses, 'recommendation' => 'Not Recommended', 'submit' => true])->assertUnprocessable();
        // Only the chair records the committee decision.
        $this->postJson($this->url('/committee/decision'), ['decision' => 'recommended', 'reasons' => 'x'])->assertForbidden();

        $this->actingAs($this->chair)->postJson($this->url('/committee/decision'), ['decision' => 'recommended', 'reasons' => 'Meets all essential criteria.'])->assertOk();
        $this->assertSame('recommended', $this->application->fresh()->committee_status);
        $this->getJson("/api/admin/committees/{$committee->id}/comparison")->assertOk()->assertJsonCount(1, 'rows');
        $this->assertDatabaseHas('audit_logs', ['action' => 'committee.decision_recorded']);

        // Committee members cannot reach the applicant's edit endpoints.
        $this->putJson("/api/applications/{$this->application->uuid}/sections/suitability", ['suitability_statement' => 'x'])->assertForbidden();
    }

    public function test_forwarding_requires_a_scrutiny_recommendation(): void
    {
        $committee = $this->committee();
        $this->actingAs($this->admin)->postJson('/api/admin/applications/forward', [
            'application_uuids' => [$this->application->uuid], 'committee_id' => $committee->id,
        ])->assertUnprocessable();
    }
}
