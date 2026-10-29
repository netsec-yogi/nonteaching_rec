<?php

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\FeePaymentConfig;
use App\Models\FeePaymentConfigRevision;
use App\Models\Role;
use App\Services\AdvertisementService;
use App\Services\ApplicationForm\ApplicationFormService;
use Database\Seeders\DemoData;
use Database\Seeders\SampleFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/** Application fee bank details / QR code configuration, the applicant payment panel and fee verification. */
class FeePaymentConfigTest extends TestCase
{
    private FeePaymentConfig $demo;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
        $this->demo = FeePaymentConfig::where('advertisement_id', $this->ad->id)->firstOrFail();
    }

    private function bank(array $overrides = []): array
    {
        return [
            'account_holder' => 'ABV-IIITM Gwalior Recruitment', 'bank_name' => 'Punjab National Bank', 'branch_name' => 'Gwalior Main',
            'account_number' => '123456789012', 'account_type' => 'current', 'ifsc' => 'PUNB0123400', 'upi_id' => 'iiitm.fee@pnb',
            'instructions' => 'Pay by NEFT.', 'reference_instructions' => 'Mention your email.', ...$overrides,
        ];
    }

    public function test_super_admin_creates_a_configuration_with_a_qr_code_and_retrieves_it(): void
    {
        $res = $this->actingAs($this->super)->post('/api/admin/fee-configs', [
            'advertisement_id' => $this->ad->id, 'post_id' => $this->post->id, ...$this->bank(), 'qr' => SampleFiles::qr(),
        ])->assertCreated()
            ->assertJsonPath('account_number', '123456789012')
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('qr.width', 300)
            ->assertJsonPath('post.code', 'JAO');
        $id = $res->json('id');

        $this->assertStringNotContainsString('qr_path', $res->getContent());
        $this->assertStringNotContainsString('payment-configs/', $res->getContent());
        // Stored encrypted at rest.
        $this->assertNotSame('123456789012', \DB::table('fee_payment_configs')->where('id', $id)->value('account_number'));

        $this->get("/api/admin/fee-configs/$id/qr")->assertOk()->assertHeader('content-type', 'image/png');
        $this->getJson('/api/admin/fee-configs')->assertOk()->assertJsonFragment(['account_number_masked' => '••••••••9012']);
    }

    public function test_invalid_qr_uploads_and_bank_details_are_rejected(): void
    {
        $this->actingAs($this->super);
        $post = fn (array $extra) => $this->post('/api/admin/fee-configs', ['advertisement_id' => $this->ad->id, ...$this->bank(), ...$extra]);

        $post(['qr' => SampleFiles::pdf('qr.pdf')])->assertUnprocessable()->assertJsonValidationErrors('qr');
        $post(['qr' => SampleFiles::qr('tiny.png', 80)])->assertUnprocessable()->assertJsonValidationErrors('qr');
        // A PDF renamed to .png with an image MIME type claimed by the browser.
        $fake = UploadedFile::fake()->createWithContent('qr.png', "%PDF-1.4\n%fake");
        $post(['qr' => $fake])->assertUnprocessable()->assertJsonValidationErrors('qr');
        $post(['ifsc' => 'PUNB123'])->assertUnprocessable()->assertJsonValidationErrors('ifsc');
        $post(['account_number' => '12AB34'])->assertUnprocessable()->assertJsonValidationErrors('account_number');
        $post(['upi_id' => 'not-a-upi'])->assertUnprocessable()->assertJsonValidationErrors('upi_id');
        $post(['qr' => SampleFiles::qr('qr.jpg', 300, 'jpeg')])->assertCreated()->assertJsonPath('qr.mime', 'image/jpeg');
    }

    public function test_activation_requires_complete_details(): void
    {
        $this->actingAs($this->super)
            ->post('/api/admin/fee-configs', ['advertisement_id' => $this->ad->id, 'post_id' => $this->post->id, 'bank_name' => 'PNB', 'is_active' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->assertSame(1, FeePaymentConfig::count());
    }

    public function test_configuration_endpoints_are_restricted(): void
    {
        $applicant = $this->applicant('someone@example.com');
        $this->actingAs($applicant)->getJson('/api/admin/fee-configs')->assertForbidden();
        $this->actingAs($this->admin)->getJson('/api/admin/fee-configs')->assertForbidden();
        $this->post("/api/admin/fee-configs/{$this->demo->id}", ['bank_name' => 'X'])->assertForbidden();
        $this->actingAs($this->scrutiny)->get("/api/admin/fee-configs/{$this->demo->id}/qr")->assertForbidden();

        // Granted the permission, a Recruitment Administrator still only reaches advertisements they manage.
        $role = Role::where('slug', Role::RECRUITMENT_ADMIN)->first();
        $this->actingAs($this->super)->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => $role->permissions()->pluck('slug')->push('payments.configure')->all()])->assertOk();
        $this->actingAs($this->admin->fresh())->getJson("/api/admin/fee-configs/{$this->demo->id}")->assertOk();
        $this->ad->administrators()->detach($this->admin->id);
        $this->getJson("/api/admin/fee-configs/{$this->demo->id}")->assertForbidden();
        $this->post('/api/admin/fee-configs', ['advertisement_id' => $this->ad->id, ...$this->bank()])->assertForbidden();
    }

    public function test_applicant_sees_the_configured_details_and_only_their_own_qr_code(): void
    {
        $this->actingAs($this->super)->post("/api/admin/fee-configs/{$this->demo->id}", ['qr' => SampleFiles::qr()])->assertOk();
        $draft = $this->completedDraft();

        $res = $this->actingAs($draft->user)->getJson("/api/applications/{$draft->uuid}")->assertOk();
        $res->assertJsonPath('application.fee.amount', fn ($v) => (float) $v === 500.0)
            ->assertJsonPath('application.fee_payment.bank_name', 'State Bank of India')
            ->assertJsonPath('application.fee_payment.account_number', '00000000000000')
            ->assertJsonPath('application.fee_payment.has_qr', true)
            ->assertJsonPath('application.fee_payment.source', 'current');
        $this->get("/api/applications/{$draft->uuid}/fee-qr")->assertOk()->assertHeader('content-type', 'image/png');

        $other = $this->applicant('other@example.com');
        $this->actingAs($other)->get("/api/applications/{$draft->uuid}/fee-qr")->assertForbidden();
        $this->getJson("/api/applications/{$draft->uuid}")->assertForbidden();
    }

    public function test_exempted_applicants_see_no_payment_details(): void
    {
        $applicant = $this->applicant('exempt@example.com');
        Auth::guard('web')->setUser($applicant);
        $app = app(ApplicationFormService::class)->create($applicant, $this->post);
        DemoData::fill($app, false); // female candidate: FEMALE exemption in the demo schedule

        $this->actingAs($applicant)->getJson("/api/applications/{$app->uuid}")
            ->assertJsonPath('application.fee.amount', fn ($v) => (float) $v === 0.0)
            ->assertJsonPath('application.fee_payment', null);
        $this->get("/api/applications/{$app->uuid}/fee-qr")->assertNotFound();
    }

    public function test_post_specific_configuration_overrides_and_other_advertisements_are_unaffected(): void
    {
        $this->actingAs($this->super);
        $postConfig = $this->post('/api/admin/fee-configs', ['advertisement_id' => $this->ad->id, 'post_id' => $this->post->id, ...$this->bank(), 'is_active' => '1'])->assertCreated();

        $draft = $this->completedDraft();
        $this->actingAs($draft->user)->getJson("/api/applications/{$draft->uuid}")->assertJsonPath('application.fee_payment.bank_name', 'Punjab National Bank');

        // The advertisement-wide configuration still applies to the other posts.
        $this->assertTrue($this->demo->fresh()->is_active);

        // A configuration for another advertisement changes nothing here.
        $other = Advertisement::create([
            'recruitment_cycle_id' => $this->ad->recruitment_cycle_id, 'number' => 'IIITM/NT/2026/09', 'title' => 'Other', 'opens_at' => now(),
            'closes_at' => now()->addMonth(), 'reference_prefix' => 'X', 'payment_methods' => ['neft'], 'fee_schedule' => ['default' => 300],
        ]);
        $this->actingAs($this->super)->post('/api/admin/fee-configs', ['advertisement_id' => $other->id, ...$this->bank(['bank_name' => 'Canara Bank', 'ifsc' => 'CNRB0001234']), 'is_active' => '1'])->assertCreated();
        $this->assertTrue($this->demo->fresh()->is_active);
        $this->assertSame('Punjab National Bank', FeePaymentConfig::find($postConfig->json('id'))->bank_name);

        // Scope cannot be moved to another advertisement after creation.
        $this->post("/api/admin/fee-configs/{$postConfig->json('id')}", ['advertisement_id' => $other->id, 'confirm' => '1'])->assertUnprocessable()->assertJsonValidationErrors('advertisement_id');
    }

    public function test_changing_an_active_configuration_needs_confirmation_is_versioned_and_keeps_paid_snapshots(): void
    {
        $draft = $this->completedDraft(); // payment saved against version 1
        $payment = $draft->payment;
        $this->assertSame($this->demo->id, $payment->fee_payment_config_id);
        $this->assertSame(1, $payment->fee_payment_config_version);
        $this->assertSame('SBIN0000000', $payment->payment_config_snapshot['ifsc']);

        $this->actingAs($this->super)
            ->post("/api/admin/fee-configs/{$this->demo->id}", ['bank_name' => 'Bank of Baroda', 'ifsc' => 'BARB0GWALIO', 'account_number' => '98765432109876'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertSame('State Bank of India', $this->demo->fresh()->bank_name);

        $this->post("/api/admin/fee-configs/{$this->demo->id}", ['bank_name' => 'Bank of Baroda', 'ifsc' => 'BARB0GWALIO', 'account_number' => '98765432109876', 'confirm' => '1', 'reason' => 'Account moved'])
            ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('revisions.0.reason', 'Account moved');

        // Applicants now see the new details; the payment keeps what it was made against.
        $this->actingAs($draft->user)->getJson("/api/applications/{$draft->uuid}")->assertJsonPath('application.fee_payment.bank_name', 'Bank of Baroda');
        $this->assertSame('SBIN0000000', $payment->fresh()->payment_config_snapshot['ifsc']);
        $this->assertSame(1, $payment->fresh()->fee_payment_config_version);

        // History is append-only, and the audit trail never carries the full account number.
        $this->assertSame(2, FeePaymentConfigRevision::where('fee_payment_config_id', $this->demo->id)->count());
        $entry = AuditLog::where('action', 'fee_payment_config.updated')->latest('id')->firstOrFail();
        $this->assertSame('••••••••••9876', $entry->new_values['account_number']);
        $this->assertStringNotContainsString('98765432109876', json_encode($entry->new_values));
        $this->expectException(LogicException::class);
        FeePaymentConfigRevision::first()->update(['reason' => 'tampered']);
    }

    public function test_activating_a_replacement_deactivates_the_previous_configuration(): void
    {
        $this->completedDraft();
        $this->actingAs($this->super);
        $new = $this->post('/api/admin/fee-configs', ['advertisement_id' => $this->ad->id, ...$this->bank()])->assertCreated()->json('id');

        $this->postJson("/api/admin/fee-configs/$new/status", ['active' => true])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->postJson("/api/admin/fee-configs/$new/status", ['active' => true, 'confirm' => true])->assertOk()->assertJsonPath('is_active', true);
        $this->assertFalse($this->demo->fresh()->is_active);
        $this->assertSame('deactivated', $this->demo->revisions()->first()->event);

        // A configuration paid against is kept; an unused inactive one can be deleted.
        $this->deleteJson("/api/admin/fee-configs/{$this->demo->id}")->assertUnprocessable();
        $unused = $this->post('/api/admin/fee-configs', ['advertisement_id' => $this->ad->id, ...$this->bank()])->json('id');
        $this->deleteJson("/api/admin/fee-configs/$unused")->assertOk();
    }

    public function test_copying_creates_an_independent_inactive_configuration(): void
    {
        $this->actingAs($this->super)->post("/api/admin/fee-configs/{$this->demo->id}", ['qr' => SampleFiles::qr()])->assertOk();
        $copy = $this->postJson("/api/admin/fee-configs/{$this->demo->id}/copy", ['advertisement_id' => $this->ad->id, 'post_id' => $this->post->id])
            ->assertCreated()->assertJsonPath('is_active', false)->assertJsonPath('account_number', '00000000000000')->assertJsonPath('qr.available', true);

        $this->post("/api/admin/fee-configs/{$copy->json('id')}", ['bank_name' => 'Changed in the copy'])->assertOk();
        $this->assertSame('State Bank of India', $this->demo->fresh()->bank_name);
        $this->assertNotSame($this->demo->fresh()->qr_path, FeePaymentConfig::find($copy->json('id'))->qr_path);
    }

    public function test_advertisement_with_a_fee_cannot_be_published_without_active_bank_details(): void
    {
        $other = Advertisement::create([
            'recruitment_cycle_id' => $this->ad->recruitment_cycle_id, 'number' => 'IIITM/NT/2026/10', 'title' => 'Other', 'opens_at' => now(),
            'closes_at' => now()->addMonth(), 'reference_prefix' => 'Y', 'payment_methods' => ['neft'], 'fee_schedule' => ['default' => 300],
        ]);
        $other->posts()->create(['code' => 'LIB', 'name' => 'Library Assistant', 'total_vacancies' => 1, 'status' => 'open', 'sort_order' => 0]);

        $this->assertContains('Configure and activate the application fee bank details (Administration → Application fee settings) for: LIB.', app(AdvertisementService::class)->publishProblems($other));
        $this->actingAs($this->super)->post('/api/admin/fee-configs', ['advertisement_id' => $other->id, ...$this->bank(), 'is_active' => '1'])->assertCreated();
        $this->assertNotContains('Configure and activate the application fee bank details (Administration → Application fee settings) for: LIB.', app(AdvertisementService::class)->publishProblems($other->fresh()));
    }

    public function test_fee_verification_list_and_decisions(): void
    {
        $app = $this->submitted();
        $this->assertSame('pending_verification', $app->fee_status);

        $this->actingAs($this->admin)->getJson('/api/admin/payments')->assertOk()
            ->assertJsonPath('data.0.uuid', $app->uuid)
            ->assertJsonPath('data.0.fee_status', 'pending_verification')
            ->assertJsonPath('data.0.amount_due', fn ($v) => (float) $v === 500.0)
            ->assertJsonPath('data.0.discrepancy', false)
            ->assertJsonPath('data.0.config_version', 1)
            ->assertJsonPath('counts.pending_verification', 1);

        $this->postJson("/api/admin/applications/{$app->uuid}/payment", ['status' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('remarks');
        $this->postJson("/api/admin/applications/{$app->uuid}/payment", ['status' => 'rejected', 'remarks' => 'UTR not found in the bank statement'])->assertOk()->assertJsonPath('fee_status', 'rejected');
        $this->getJson('/api/admin/payments?status=rejected')->assertJsonPath('data.0.remarks', 'UTR not found in the bank statement')
            ->assertJsonPath('data.0.verified_by', $this->admin->name);
        $this->assertDatabaseHas('payment_verifications', ['status' => 'rejected', 'verified_by' => $this->admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.verification_recorded', 'application_id' => $app->id]);

        // The applicant sees the details their payment was made against, not later edits.
        $this->actingAs($app->user)->getJson("/api/applications/{$app->uuid}")->assertJsonPath('application.fee_payment.source', 'as_paid');

        // Applicants and users without the verification permission cannot use the list.
        $this->actingAs($app->user)->getJson('/api/admin/payments')->assertForbidden();
        $this->actingAs($this->chair)->getJson('/api/admin/payments')->assertForbidden();
    }

    public function test_a_draft_payment_cannot_be_verified(): void
    {
        $draft = $this->completedDraft();
        $this->assertSame(Application::DRAFT, $draft->status);
        $this->actingAs($this->super)->postJson("/api/admin/applications/{$draft->uuid}/payment", ['status' => 'verified'])->assertUnprocessable();
        $this->getJson('/api/admin/payments?status=details_submitted')->assertJsonPath('data.0.uuid', $draft->uuid);
    }
}
