<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Services\ScrutinyService;
use Database\Seeders\SampleFiles;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The Mandatory setting under Admin → Document Types reaches the applicant form, submission and scrutiny. */
class DocumentMandatoryStatusTest extends TestCase
{
    private Application $draft;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
        $this->draft = $this->completedDraft(); // OBC, in service, fee payable
    }

    private function createType(array $overrides = []): DocumentType
    {
        $id = $this->actingAs($this->super)->postJson('/api/admin/document-types', [
            'code' => 'matric_certificate', 'name' => 'Matriculation certificate', 'allowed_mimes' => ['application/pdf'], 'max_kb' => 1024,
            'step' => 'personal', 'is_mandatory' => true, 'condition' => 'always', 'accepts_undertaking' => false, 'is_active' => true, ...$overrides,
        ])->assertCreated()->json('id');

        return DocumentType::findOrFail($id);
    }

    private function requirement(string $code): ?array
    {
        return collect($this->actingAs($this->draft->user)->getJson("/api/applications/{$this->draft->uuid}")->json('requirements'))->firstWhere('document_type', $code);
    }

    private function submit()
    {
        return $this->actingAs($this->draft->user)->postJson("/api/applications/{$this->draft->uuid}/submit", ['confirm' => true]);
    }

    public function test_a_mandatory_document_type_is_mandatory_on_the_form_and_at_submission(): void
    {
        $type = $this->createType();
        $this->assertTrue($this->requirement('matric_certificate')['is_mandatory']);
        $this->submit()->assertUnprocessable()->assertJsonFragment(['Upload required: Matriculation certificate.']);

        // Changed in Administration: the form follows immediately, and the missing optional document no longer blocks.
        $this->actingAs($this->super)->putJson("/api/admin/document-types/{$type->id}", [...$type->only(['code', 'name', 'allowed_mimes', 'max_kb', 'step']), 'is_mandatory' => false, 'is_active' => true])->assertOk();
        $this->assertNull($this->requirement('matric_certificate')); // shown as an optional upload slot
        $this->submit()->assertOk();
    }

    public function test_uploading_the_mandatory_document_satisfies_it(): void
    {
        $this->createType();
        $this->actingAs($this->draft->user)->post("/api/applications/{$this->draft->uuid}/documents", ['file' => SampleFiles::pdf(), 'document_type' => 'matric_certificate'])->assertCreated();
        $this->submit()->assertOk();
    }

    public function test_the_type_condition_is_evaluated_for_the_candidate(): void
    {
        $this->createType(['condition' => 'pwd']);
        $this->assertNull($this->requirement('matric_certificate')); // the candidate is not PwBD
        $this->submit()->assertOk();
    }

    public function test_an_advertisement_requirement_takes_precedence_and_can_be_realigned(): void
    {
        $type = DocumentType::where('code', 'id_proof')->firstOrFail();
        $req = DocumentRequirement::where('advertisement_id', $this->ad->id)->where('document_type_id', $type->id)->firstOrFail();
        $req->update(['is_mandatory' => false]);
        $this->assertFalse($this->requirement('id_proof')['is_mandatory']);

        $types = collect($this->actingAs($this->super)->getJson('/api/admin/document-types')->json('types'))->keyBy('code');
        $this->assertSame(1, $types['id_proof']['advertisement_overrides']);
        $this->putJson("/api/admin/document-types/{$type->id}", [...$type->only(['code', 'name', 'allowed_mimes', 'max_kb', 'step']), 'is_mandatory' => true, 'is_active' => true, 'apply_to_advertisements' => true])->assertOk();
        $this->assertNull($req->fresh()->is_mandatory);
        $this->assertTrue($this->requirement('id_proof')['is_mandatory']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document_requirement.updated', 'auditable_id' => $req->id]);
    }

    public function test_an_advertisement_condition_that_does_not_hold_is_not_replaced_by_the_type_default(): void
    {
        $this->assertTrue(DocumentType::where('code', 'category_certificate')->value('is_mandatory'));
        $this->assertNotNull($this->requirement('category_certificate')); // OBC
        $data = $this->getJson("/api/applications/{$this->draft->uuid}")->json('data.personal');
        $this->putJson("/api/applications/{$this->draft->uuid}/sections/personal", [...$data, 'category' => 'UR'])->assertOk();
        $this->assertNull($this->requirement('category_certificate'));
    }

    public function test_undertakings_follow_the_configuration_for_type_level_requirements(): void
    {
        $this->createType(['accepts_undertaking' => false]);
        $url = "/api/applications/{$this->draft->uuid}/documents";
        $this->actingAs($this->draft->user)->post($url, ['file' => SampleFiles::pdf(), 'document_type' => 'matric_certificate', 'upload_type' => 'undertaking'])
            ->assertUnprocessable()->assertJsonValidationErrors('upload_type');
        $this->submit()->assertUnprocessable(); // the original remains outstanding

        DocumentType::where('code', 'matric_certificate')->update(['accepts_undertaking' => true]);
        $this->assertTrue($this->requirement('matric_certificate')['accepts_undertaking']);
        $this->actingAs($this->draft->user)->post($url, ['file' => SampleFiles::pdf(), 'document_type' => 'matric_certificate', 'upload_type' => 'undertaking'])->assertCreated();
        $this->submit()->assertOk();
        $row = collect(app(ScrutinyService::class)->documentChecklist($this->draft->fresh()))->firstWhere('document_type', 'matric_certificate');
        $this->assertSame(['undertaking', true, 'document_type'], [$row['status'], $row['original_required'], $row['source']]);
    }

    public function test_scrutiny_lists_missing_type_level_mandatory_documents(): void
    {
        $app = $this->submitted($this->applicant('second@example.com'));
        $this->createType();
        $row = collect(app(ScrutinyService::class)->documentChecklist($app))->firstWhere('document_type', 'matric_certificate');
        $this->assertSame(['missing', true, 'document_type'], [$row['status'], $row['mandatory'], $row['source']]);
        $this->assertContains('Mandatory document not uploaded: Matriculation certificate', app(ScrutinyService::class)->report($app)['deficiencies']);
    }

    public function test_endorsement_documents_are_not_demanded_where_the_endorsement_does_not_apply(): void
    {
        $this->createType(['code' => 'forwarding_letter', 'name' => 'Forwarding letter', 'step' => 'endorsement', 'condition' => 'in_service']);
        $this->assertNotNull($this->requirement('forwarding_letter'));
        $this->ad->update(['endorsement_mode' => 'not_applicable']);
        $this->post->update(['endorsement_mode' => null]);
        $this->assertNull($this->requirement('forwarding_letter'));
    }

    public function test_existing_uploads_are_untouched(): void
    {
        $before = $this->draft->documents()->pluck('sha256', 'uuid')->all();
        $this->createType();
        DocumentType::where('code', 'matric_certificate')->update(['is_mandatory' => false]);
        $this->assertSame($before, $this->draft->documents()->pluck('sha256', 'uuid')->all());
    }
}
