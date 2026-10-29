<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Services\ApplicationForm\Sections\PaymentSection;
use App\Services\ScrutinyService;
use Database\Seeders\SampleFiles;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Personal details before the fee step; the fee step only when a fee applies; mandatory / optional document types. */
class FormOrderFeeAndDocumentRequirementsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
    }

    private function keys(Application $app): array
    {
        return array_column($this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")->json('steps'), 'key');
    }

    private function savePersonal(Application $app, array $changes): TestResponse
    {
        $data = $this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")->json('data.personal');

        return $this->putJson("/api/applications/{$app->uuid}/sections/personal", array_merge($data, $changes));
    }

    // ------------------------------------------------------------------ order

    public function test_personal_details_come_first_and_the_fee_step_second(): void
    {
        $applicant = $this->applicant();
        $uuid = $this->actingAs($applicant)->postJson('/api/applications', ['post_id' => $this->post->id])->assertCreated()->json('uuid');
        $app = Application::where('uuid', $uuid)->firstOrFail();

        $this->assertSame('personal', $app->current_step);
        $this->assertSame(['personal', 'payment', 'post'], array_slice($this->keys($app), 0, 3));
        // Paper-form references are unchanged.
        $steps = collect($this->getJson("/api/applications/{$uuid}")->json('steps'))->keyBy('key');
        $this->assertSame('5–16', $steps['personal']['form_reference']);
        $this->assertSame('1', $steps['payment']['form_reference']);
    }

    // ------------------------------------------------------------------ fee step visibility

    public function test_a_fee_paying_applicant_sees_and_completes_the_fee_step(): void
    {
        $app = $this->completedDraft(); // OBC male: Rs. 500 under the demo schedule
        $this->assertContains('payment', $this->keys($app));
        $this->getJson("/api/applications/{$app->uuid}")
            ->assertJsonPath('application.fee_payment.bank_name', 'State Bank of India')
            ->assertJsonPath('application.advertisement.payment_methods', ['neft', 'demand_draft']);
        $this->assertSame('pending_verification', $app->payment->status);
        // The browser's amount is not authoritative: less than the server-calculated fee is refused.
        $this->putJson("/api/applications/{$app->uuid}/sections/payment", ['method' => 'neft', 'utr_number' => 'ABC123456', 'transaction_date' => now()->toDateString(), 'amount' => 1, 'bank_name' => 'SBI'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_a_zero_fee_applicant_never_sees_or_needs_payment_details(): void
    {
        $app = $this->completedDraft();
        $this->savePersonal($app, ['category' => 'SC'])->assertOk();
        $app->refresh();

        $this->assertNotContains('payment', $this->keys($app));
        $this->getJson("/api/applications/{$app->uuid}")
            ->assertJsonPath('application.fee_payment', null)
            ->assertJsonPath('application.advertisement.payment_instructions', null)
            ->assertJsonPath('application.advertisement.payment_methods', []);
        // The stale NEFT entry is replaced by an exemption record, and the replacement is audited.
        $this->assertSame('exempt', $app->payment->status);
        $this->assertSame('SC', $app->payment->exemption_basis);
        $this->assertTrue(AuditLog::where('action', 'payment.fee_not_payable')->where('application_id', $app->id)->exists());

        // Direct API calls cannot record a payment or payment proof for a zero fee.
        $this->putJson("/api/applications/{$app->uuid}/sections/payment", ['method' => 'neft', 'utr_number' => 'ABC123456', 'transaction_date' => now()->toDateString(), 'amount' => 500, 'bank_name' => 'SBI'])
            ->assertUnprocessable()->assertJsonValidationErrors('section');
        $this->post("/api/applications/{$app->uuid}/documents", ['file' => SampleFiles::pdf(), 'document_type' => 'fee_receipt'])->assertUnprocessable();
        $this->get("/api/applications/{$app->uuid}/fee-qr")->assertNotFound();

        // Nothing about the fee blocks submission; it is recorded as exempt, not unpaid.
        $this->postJson("/api/applications/{$app->uuid}/submit", ['confirm' => true])->assertOk();
        $app->refresh();
        $this->assertSame('exempt', $app->fee_status);
        $this->actingAs($this->admin)->getJson('/api/admin/payments?status=exempt')->assertJsonPath('data.0.uuid', $app->uuid);
        $this->getJson('/api/admin/payments?status=pending_payment')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/admin/payments?status=pending_verification')->assertJsonPath('meta.total', 0);
    }

    public function test_a_schedule_without_any_fee_records_not_required(): void
    {
        $this->post->update(['fee_schedule' => ['default' => 0, 'exemptions' => []]]);
        $app = $this->completedDraft();
        $this->assertNotContains('payment', $this->keys($app));
        $this->postJson("/api/applications/{$app->uuid}/submit", ['confirm' => true])->assertOk();
        $this->assertSame('not_required', $app->fresh()->fee_status);
        $this->actingAs($this->admin)->getJson('/api/admin/payments?status=not_required')->assertJsonPath('data.0.uuid', $app->uuid);
    }

    public function test_a_fee_becoming_payable_again_asks_for_payment(): void
    {
        $app = $this->completedDraft();
        $this->savePersonal($app, ['category' => 'ST'])->assertOk();
        $this->assertSame('exempt', $app->fresh()->payment->status);

        $this->savePersonal($app, ['category' => 'OBC'])->assertOk();
        $this->assertNull($app->fresh()->payment);
        $this->assertTrue(AuditLog::where('action', 'payment.exemption_cleared')->exists());
        $steps = collect($this->getJson("/api/applications/{$app->uuid}")->json('steps'))->keyBy('key');
        $this->assertContains('Enter the details of your application fee payment.', $steps['payment']['issues']);
        $this->postJson("/api/applications/{$app->uuid}/submit", ['confirm' => true])->assertUnprocessable();
    }

    public function test_a_verified_payment_is_never_rewritten_when_the_fee_later_becomes_zero(): void
    {
        $app = $this->completedDraft();
        $app->payment->forceFill(['status' => 'verified', 'verified_by' => $this->admin->id, 'verified_at' => now()])->save();
        $app->personal()->update(['category' => 'SC']);

        $this->assertSame('verified', app(PaymentSection::class)->settle($app->fresh()));
        $this->assertSame('neft', $app->payment->fresh()->method);
    }

    // ------------------------------------------------------------------ document types

    public function test_administrators_set_the_mandatory_option_on_document_types(): void
    {
        $this->actingAs($this->super);
        $body = ['name' => 'Matriculation certificate', 'allowed_mimes' => ['application/pdf'], 'max_kb' => 1024, 'step' => 'qualifications', 'is_active' => true];
        $mandatory = $this->postJson('/api/admin/document-types', [...$body, 'code' => 'matric', 'is_mandatory' => true])->assertCreated()->assertJsonPath('is_mandatory', true)->json('id');
        $this->postJson('/api/admin/document-types', [...$body, 'code' => 'extra_cert', 'is_mandatory' => false])->assertCreated()->assertJsonPath('is_mandatory', false);
        $this->putJson("/api/admin/document-types/$mandatory", [...$body, 'code' => 'matric', 'is_mandatory' => false])->assertOk()->assertJsonPath('is_mandatory', false);
        $this->postJson('/api/admin/document-types', [...$body, 'code' => 'bad', 'is_mandatory' => 'perhaps'])->assertUnprocessable()->assertJsonValidationErrors('is_mandatory');

        $listed = collect($this->getJson('/api/admin/document-types')->json('types'))->keyBy('code');
        $this->assertFalse($listed['matric']['is_mandatory']);
        $this->assertTrue(AuditLog::where('action', 'document_type.updated')->exists());

        $this->actingAs($this->admin)->putJson("/api/admin/document-types/$mandatory", [...$body, 'code' => 'matric', 'is_mandatory' => true])->assertForbidden();
        $this->actingAs($this->scrutiny)->getJson('/api/admin/document-types')->assertForbidden();
    }

    public function test_requirements_follow_the_document_type_unless_the_advertisement_overrides_it(): void
    {
        $app = $this->completedDraft();
        $type = DocumentType::where('code', 'id_proof')->firstOrFail();
        $req = DocumentRequirement::where('advertisement_id', $this->ad->id)->where('document_type_id', $type->id)->firstOrFail();
        $app->documents()->where('document_type_id', $type->id)->get()->each->delete();

        $blocked = fn () => collect($this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")->json('steps'))
            ->firstWhere('key', 'personal')['issues'];
        // Explicit value set for this advertisement (as existing requirements keep after the migration).
        $this->assertContains('Upload required: '.$type->name.'.', $blocked());

        // Follow the type's default: non-mandatory type → no longer blocks; mandatory → blocks again.
        $req->update(['is_mandatory' => null]);
        $type->update(['is_mandatory' => false]);
        $this->assertNotContains('Upload required: '.$type->name.'.', $blocked());
        $this->getJson("/api/applications/{$app->uuid}")->assertJsonFragment(['document_type' => 'id_proof', 'is_mandatory' => false]);
        $type->update(['is_mandatory' => true]);
        $this->assertContains('Upload required: '.$type->name.'.', $blocked());
        $this->postJson("/api/applications/{$app->uuid}/submit", ['confirm' => true])->assertUnprocessable();

        // An advertisement-level override beats the type default.
        $req->update(['is_mandatory' => false]);
        $this->assertNotContains('Upload required: '.$type->name.'.', $blocked());
        $this->postJson("/api/applications/{$app->uuid}/submit", ['confirm' => true])->assertOk();
    }

    public function test_conditional_requirements_only_apply_to_the_candidates_they_concern(): void
    {
        DocumentType::where('code', 'category_certificate')->update(['is_mandatory' => true]);
        DocumentRequirement::where('advertisement_id', $this->ad->id)->update(['is_mandatory' => null]);
        $app = $this->completedDraft();
        $app->documents()->whereHas('documentType', fn ($q) => $q->where('code', 'category_certificate'))->get()->each->delete();
        $personalIssues = fn () => collect($this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")->json('steps'))->firstWhere('key', 'personal')['issues'];

        $this->assertContains('Upload required: Category certificate (SC / ST / OBC-NCL / EWS).', $personalIssues()); // OBC
        $this->savePersonal($app, ['category' => 'UR'])->assertOk();
        $this->assertNotContains('Upload required: Category certificate (SC / ST / OBC-NCL / EWS).', $personalIssues());
    }

    public function test_requirement_override_can_be_cleared_through_the_advertisement_screen(): void
    {
        $req = DocumentRequirement::where('advertisement_id', $this->ad->id)->firstOrFail();
        $this->actingAs($this->super)->putJson("/api/admin/advertisements/{$this->ad->id}/requirements/{$req->id}", [
            'document_type_id' => $req->document_type_id, 'condition' => $req->condition, 'is_mandatory' => null,
        ])->assertOk()->assertJsonPath('is_mandatory', null)->assertJsonPath('effective_mandatory', true);
    }

    // ------------------------------------------------------------------ scrutiny

    public function test_scrutiny_shows_missing_mandatory_documents_but_not_missing_optional_ones(): void
    {
        $app = $this->submitted();
        $photo = DocumentType::where('code', 'photo')->firstOrFail();
        $optional = DocumentType::where('code', 'case_details')->firstOrFail();
        // Requirements added after submission: a mandatory one the candidate could not meet, and an optional one.
        DocumentRequirement::where('advertisement_id', $this->ad->id)->where('document_type_id', $photo->id)->delete();
        DocumentRequirement::create(['advertisement_id' => $this->ad->id, 'document_type_id' => $photo->id, 'condition' => 'always', 'is_mandatory' => true]);
        DocumentRequirement::where('advertisement_id', $this->ad->id)->where('document_type_id', $optional->id)->update(['condition' => 'always', 'is_mandatory' => false]);
        $app->documents()->where('document_type_id', $photo->id)->update(['is_frozen' => false]);
        $app->documents()->where('document_type_id', $photo->id)->get()->each->delete();

        $docs = collect(app(ScrutinyService::class)->documentChecklist($app->fresh()))->keyBy('document_type');
        $this->assertSame('missing', $docs['photo']['status']);
        $this->assertTrue($docs['photo']['mandatory']);
        $this->assertSame(0, $app->documents()->where('document_type_id', $optional->id)->count());
        $this->assertSame('not_provided', $docs['case_details']['status']);

        $this->actingAs($this->super)->getJson("/api/admin/applications/{$app->uuid}/scrutiny")->assertOk()->assertJsonFragment(['document_type' => 'photo', 'status' => 'missing']);
        $report = app(ScrutinyService::class)->report($app->fresh());
        $this->assertContains('Mandatory document not uploaded: Passport-size photograph', $report['deficiencies']);
        $this->assertEmpty(array_filter($report['deficiencies'], fn ($d) => str_contains($d, 'case')));

        // Verifying an uploaded document still works, and the missing mandatory one keeps the documents deficient.
        $doc = $app->documents()->firstOrFail();
        $this->postJson("/api/admin/applications/{$app->uuid}/documents/{$doc->uuid}/verify", ['status' => 'verified'])->assertOk();
        $this->assertSame('deficient', $app->fresh()->document_status);
    }
}
