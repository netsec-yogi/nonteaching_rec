<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\AuditLog;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Services\ScrutinyService;
use App\Support\IdentityNumbers;
use Database\Seeders\SampleFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Candidate signature, optional PAN / Aadhaar, and signed undertakings in place of missing documents. */
class SignatureIdentityUndertakingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
    }

    /** A well-formed Aadhaar number (valid Verhoeff check digit) built from 11 digits. */
    private function aadhaar(string $first11 = '23456789012'): string
    {
        foreach (range(0, 9) as $d) {
            if (IdentityNumbers::aadhaarFormatValid($first11.$d)) {
                return $first11.$d;
            }
        }
        $this->fail('No check digit found.');
    }

    private function savePersonal(Application $app, array $changes)
    {
        $data = $this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")->json('data.personal');

        return $this->putJson("/api/applications/{$app->uuid}/sections/personal", array_merge($data, $changes));
    }

    private function upload(Application $app, string $type, $file, array $extra = [])
    {
        return $this->actingAs($app->user)->post("/api/applications/{$app->uuid}/documents", ['file' => $file, 'document_type' => $type, ...$extra]);
    }

    // ------------------------------------------------------------------ signature

    public function test_candidate_uploads_previews_and_replaces_a_signature(): void
    {
        $app = $this->completedDraft();
        $this->upload($app, 'signature', SampleFiles::pdf('sig.pdf'))->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->upload($app, 'signature', SampleFiles::qr('tiny.png', 80))->assertUnprocessable()->assertJsonValidationErrors('file');
        $first = $this->upload($app, 'signature', SampleFiles::qr('sig.png', 300))->assertCreated()->assertJsonPath('upload_type', 'original')->json('uuid');
        $this->upload($app, 'signature', SampleFiles::qr('sig2.jpg', 300, 'jpeg'), ['replace_uuid' => $first])->assertCreated();

        $docs = collect($this->getJson("/api/applications/{$app->uuid}")->json('documents'))->where('document_type', 'signature');
        $this->assertCount(1, $docs); // kept when the draft is resumed; the replaced one is gone
        $this->get("/api/applications/{$app->uuid}/documents/{$docs->first()['uuid']}?inline=1")->assertOk()->assertHeader('content-type', 'image/jpeg');
        $this->get("/api/applications/{$app->uuid}/preview.pdf")->assertOk();

        // Optional by default: no requirement, so nothing blocks submission without it.
        $this->assertFalse(DocumentType::where('code', 'signature')->value('is_mandatory'));
        $this->postJson("/api/applications/{$app->uuid}/submit", ['confirm' => true])->assertOk();
        $this->upload($app, 'signature', SampleFiles::qr('late.png'))->assertForbidden();
        $this->actingAs($this->admin)->get("/api/applications/{$app->uuid}/documents/{$docs->first()['uuid']}")->assertOk();
    }

    // ------------------------------------------------------------------ PAN / Aadhaar

    public function test_identity_numbers_are_optional_normalised_validated_and_encrypted(): void
    {
        $app = $this->completedDraft();
        $this->savePersonal($app, [])->assertOk(); // optional by default

        $this->savePersonal($app, ['pan_number' => 'ABCD1234F'])->assertUnprocessable()->assertJsonValidationErrors('pan_number');
        $this->savePersonal($app, ['aadhaar_number' => '1234 5678 9012'])->assertUnprocessable()->assertJsonValidationErrors('aadhaar_number');
        $bad = substr($this->aadhaar(), 0, 11).(((int) substr($this->aadhaar(), -1) + 1) % 10);
        $this->savePersonal($app, ['aadhaar_number' => $bad])->assertUnprocessable()->assertJsonValidationErrors('aadhaar_number');

        $aadhaar = $this->aadhaar();
        $this->savePersonal($app, ['pan_number' => ' abcde 1234f ', 'aadhaar_number' => chunk_split($aadhaar, 4, ' ')])->assertOk()
            ->assertJsonPath('data.personal.pan_number', 'ABCDE1234F')
            ->assertJsonPath('data.personal.aadhaar_number', $aadhaar);

        $row = DB::table('application_personal_details')->where('application_id', $app->id)->first();
        $this->assertStringNotContainsString('ABCDE1234F', $row->pan_number);
        $this->assertStringNotContainsString($aadhaar, $row->aadhaar_number);
        $this->assertFalse(AuditLog::query()->get()->contains(fn ($l) => str_contains(json_encode([$l->old_values, $l->new_values, $l->meta]), $aadhaar)));
    }

    public function test_an_advertisement_can_require_an_identity_number(): void
    {
        $app = $this->completedDraft();
        $this->ad->update(['identity_number_requirement' => 'pan_or_aadhaar']);
        $this->savePersonal($app, ['pan_number' => null, 'aadhaar_number' => null])->assertUnprocessable()->assertJsonValidationErrors(['pan_number', 'aadhaar_number']);
        $this->savePersonal($app, ['aadhaar_number' => $this->aadhaar()])->assertOk();

        $this->ad->update(['identity_number_requirement' => 'pan']);
        $steps = collect($this->getJson("/api/applications/{$app->uuid}")->json('steps'))->keyBy('key');
        $this->assertContains('PAN required for this advertisement: enter it in your personal information.', $steps['personal']['issues']);
    }

    public function test_identity_numbers_are_masked_for_staff_and_full_numbers_need_permission(): void
    {
        $app = $this->completedDraft();
        $aadhaar = $this->aadhaar();
        $this->savePersonal($app, ['pan_number' => 'ABCDE1234F', 'aadhaar_number' => $aadhaar])->assertOk();
        $app = $this->submittedFrom($app);

        $show = $this->actingAs($this->admin)->getJson("/api/admin/applications/{$app->uuid}")->assertOk();
        $show->assertJsonPath('identity.pan', 'XXXXXX234F')->assertJsonPath('identity.aadhaar', 'XXXX XXXX '.substr($aadhaar, -4))->assertJsonPath('identity.can_reveal', false)
            ->assertJsonPath('snapshot.personal.pan_masked', 'XXXXXX234F');
        $this->assertStringNotContainsString('ABCDE1234F', $show->getContent());
        $this->assertStringNotContainsString($aadhaar, $show->getContent());
        $this->assertStringNotContainsString($aadhaar, $this->getJson('/api/admin/applications')->getContent());
        $this->assertStringNotContainsString('ABCDE1234F', json_encode($app->versions()->first()->snapshot));

        $this->getJson("/api/admin/applications/{$app->uuid}/identity")->assertForbidden();
        $this->team();
        $this->actingAs($this->scrutiny)->getJson("/api/admin/applications/{$app->uuid}/identity")->assertForbidden();
        $this->actingAs($app->user)->getJson("/api/admin/applications/{$app->uuid}/identity")->assertForbidden();

        $this->actingAs($this->super)->getJson("/api/admin/applications/{$app->uuid}/identity")->assertOk()
            ->assertJsonPath('pan', 'ABCDE1234F')->assertJsonPath('aadhaar', $aadhaar)->assertHeader('cache-control', 'max-age=0, no-store, private');
        $this->assertDatabaseHas('document_access_logs', ['resource_type' => 'identity_numbers', 'application_id' => $app->id, 'user_id' => $this->super->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'application.identity_numbers_viewed', 'application_id' => $app->id]);
    }

    private function submittedFrom(Application $app): Application
    {
        $this->actingAs($app->user)->postJson("/api/applications/{$app->uuid}/submit", ['confirm' => true])->assertOk();

        return $app->fresh();
    }

    // ------------------------------------------------------------------ undertakings

    public function test_a_signed_undertaking_stands_in_for_a_permitted_missing_document(): void
    {
        $app = $this->completedDraft(); // OBC: the category certificate is required
        $type = DocumentType::where('code', 'category_certificate')->firstOrFail();
        $app->documents()->where('document_type_id', $type->id)->get()->each->delete();
        $issues = fn () => collect($this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")->json('steps'))->firstWhere('key', 'personal')['issues'];
        $this->assertContains("Upload required: {$type->name}.", $issues());

        $this->getJson("/api/applications/{$app->uuid}")->assertJsonFragment(['document_type' => 'category_certificate', 'accepts_undertaking' => true]);
        $doc = $this->upload($app, 'category_certificate', SampleFiles::pdf('undertaking.pdf', ApplicationDocument::UNDERTAKING_TEXT), ['upload_type' => 'undertaking'])
            ->assertCreated()->assertJsonPath('upload_type', 'undertaking')->assertJsonPath('original_status', 'awaited')->json('uuid');
        $this->assertNotContains("Upload required: {$type->name}.", $issues());

        // Not permitted for the photo ID: the original is still needed.
        $this->upload($app, 'id_proof', SampleFiles::pdf('undertaking.pdf'), ['upload_type' => 'undertaking'])->assertUnprocessable()->assertJsonValidationErrors('upload_type');

        // An advertisement can refuse undertakings for a document: then it no longer counts.
        $req = DocumentRequirement::where('advertisement_id', $this->ad->id)->where('document_type_id', $type->id)->firstOrFail();
        $req->update(['accepts_undertaking' => false]);
        $this->assertContains("Upload required: {$type->name}.", $issues());
        $req->update(['accepts_undertaking' => null]);

        $this->postJson("/api/applications/{$app->uuid}/submit", ['confirm' => true])->assertOk();
        $app->refresh();

        // Scrutiny sees an undertaking, the original still required, and is told when.
        $row = collect(app(ScrutinyService::class)->documentChecklist($app))->firstWhere('document_type', 'category_certificate');
        $this->assertSame('undertaking', $row['status']);
        $this->assertTrue($row['original_required']);
        $this->assertSame(ApplicationDocument::ORIGINAL_DUE_STAGE, $row['original_due_stage']);
        $this->assertNotEmpty(app(ScrutinyService::class)->report($app)['originals_awaited']);

        $undertaking = $app->documents()->where('uuid', $doc)->firstOrFail();
        $this->actingAs($this->admin)->postJson("/api/admin/applications/{$app->uuid}/documents/{$doc}/verify", ['status' => 'verified', 'remarks' => 'Declaration present'])->assertOk();
        $this->assertSame('awaited', $undertaking->fresh()->original_status); // accepting the undertaking does not verify the original

        // The original is produced at document verification: recorded, the undertaking kept.
        $this->postJson("/api/admin/applications/{$app->uuid}/documents/{$doc}/original", ['status' => 'received_rejected'])->assertUnprocessable()->assertJsonValidationErrors('remarks');
        $this->postJson("/api/admin/applications/{$app->uuid}/documents/{$doc}/original", ['status' => 'received_verified', 'remarks' => 'Seen at DV'])->assertOk();
        $fresh = $undertaking->fresh();
        $this->assertSame('received_verified', $fresh->original_status);
        $this->assertSame(ApplicationDocument::UNDERTAKING, $fresh->upload_type);
        $this->assertSame('verified', $fresh->verification_status);
        $this->assertSame($this->admin->id, $fresh->original_received_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.original_recorded', 'application_id' => $app->id]);
        $this->assertFalse(collect(app(ScrutinyService::class)->documentChecklist($app->fresh()))->firstWhere('document_type', 'category_certificate')['original_required']);

        // An original-type document has no undertaking to settle; scrutiny users without permission cannot record.
        $original = $app->documents()->where('upload_type', 'original')->firstOrFail();
        $this->postJson("/api/admin/applications/{$app->uuid}/documents/{$original->uuid}/original", ['status' => 'received_verified'])->assertUnprocessable();
        $this->actingAs($this->chair)->postJson("/api/admin/applications/{$app->uuid}/documents/{$doc}/original", ['status' => 'awaited'])->assertForbidden();
    }

    public function test_the_document_index_marks_undertakings_and_document_status_flags_awaited_originals(): void
    {
        $app = $this->completedDraft();
        $type = DocumentType::where('code', 'category_certificate')->firstOrFail();
        $app->documents()->where('document_type_id', $type->id)->get()->each->delete();
        $this->upload($app, 'category_certificate', SampleFiles::pdf('u.pdf'), ['upload_type' => 'undertaking'])->assertCreated();
        $app = $this->submittedFrom($app);

        $snapshot = $app->versions()->first()->snapshot;
        $this->assertContains('undertaking', array_column($snapshot['documents'], 'upload_type'));

        // Everything verified except originals still to be produced → flagged for document verification.
        $this->actingAs($this->super);
        foreach ($app->documents as $d) {
            $this->postJson("/api/admin/applications/{$app->uuid}/documents/{$d->uuid}/verify", ['status' => 'verified'])->assertOk();
        }
        $this->assertSame('originals_awaited', $app->fresh()->document_status);
    }
}
