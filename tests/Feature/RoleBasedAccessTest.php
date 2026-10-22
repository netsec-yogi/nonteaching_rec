<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Role;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/** Authorization through the real HTTP stack: hiding a button is not access control. */
class RoleBasedAccessTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->recruitment();
        $this->application = $this->submitted();
    }

    public function test_guests_and_applicants_cannot_reach_staff_routes(): void
    {
        $this->getJson('/api/admin/applications')->assertUnauthorized();
        $this->actingAs($this->application->user);
        foreach (['/api/admin/applications', '/api/admin/dashboard', '/api/admin/reports', '/api/admin/users', "/api/admin/applications/{$this->application->uuid}"] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_applicants_cannot_see_each_others_applications_or_documents(): void
    {
        $other = $this->applicant('other@example.com');
        $doc = $this->application->documents()->first();
        $this->actingAs($other);

        $this->getJson("/api/applications/{$this->application->uuid}")->assertForbidden();
        $this->getJson("/api/applications/{$this->application->uuid}/pdf")->assertForbidden();
        $this->getJson("/api/applications/{$this->application->uuid}/documents/{$doc->uuid}")->assertForbidden();
    }

    public function test_scrutiny_user_sees_only_assigned_or_team_applications(): void
    {
        $this->actingAs($this->scrutiny);
        $this->getJson("/api/admin/applications/{$this->application->uuid}")->assertForbidden();
        $this->getJson('/api/admin/applications')->assertOk()->assertJsonPath('meta.total', 0);

        $this->team();
        $this->getJson("/api/admin/applications/{$this->application->uuid}")->assertOk();
        $this->getJson('/api/admin/applications')->assertJsonPath('meta.total', 1);

        // Team membership does not extend to another advertisement's applicants.
        $this->actingAs($this->scrutiny2)->getJson("/api/admin/applications/{$this->application->uuid}")->assertForbidden();
    }

    public function test_staff_cannot_modify_the_candidates_submitted_application(): void
    {
        $this->team();
        foreach ([$this->scrutiny, $this->admin, $this->super] as $staff) {
            $this->actingAs($staff)
                ->putJson("/api/applications/{$this->application->uuid}/sections/personal", ['full_name' => 'SOMEONE ELSE'])
                ->assertForbidden();
        }
        $this->assertSame('RAVI KUMAR SHARMA', $this->application->personal()->value('full_name'));
    }

    public function test_recruitment_admin_is_limited_to_assigned_advertisements(): void
    {
        $this->actingAs($this->admin)->getJson("/api/admin/applications/{$this->application->uuid}")->assertOk();
        $this->ad->administrators()->detach($this->admin->id);
        $this->getJson("/api/admin/applications/{$this->application->uuid}")->assertForbidden();
    }

    public function test_permissions_are_configurable_per_role(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/reports/consolidated')->assertOk();
        $this->getJson('/api/admin/reports/referee_content')->assertForbidden();
        $this->postJson("/api/admin/applications/{$this->application->uuid}/reopen", ['reason' => 'x'])->assertForbidden();

        // The Super Administrator grants reopening to Recruitment Administrators.
        $role = Role::where('slug', 'recruitment_admin')->first();
        $perms = $role->permissions()->pluck('slug')->push('applications.reopen')->all();
        $this->actingAs($this->super)->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => $perms])->assertOk();

        $this->actingAs($this->admin->fresh())->postJson("/api/admin/applications/{$this->application->uuid}/reopen", ['reason' => 'Correct the date of birth'])->assertOk();
    }

    public function test_document_storage_paths_are_never_exposed(): void
    {
        $this->actingAs($this->admin);
        $body = $this->getJson("/api/admin/applications/{$this->application->uuid}")->assertOk()->getContent();
        $this->assertStringNotContainsString('applications/'.$this->application->uuid.'/', $body);
        $this->assertStringNotContainsString('"path"', $body);
        $this->assertStringNotContainsString('"disk"', $body);
        $this->assertStringNotContainsString('token', $body);

        $doc = $this->application->documents()->first();
        $this->getJson("/api/applications/{$this->application->uuid}/documents/{$doc->uuid}")
            ->assertOk()->assertHeader('x-content-type-options', 'nosniff');
        $this->assertDatabaseHas('document_access_logs', ['resource_id' => $doc->id, 'user_id' => $this->admin->id, 'action' => 'download']);
    }

    public function test_configuration_endpoints_require_their_permissions(): void
    {
        $this->actingAs($this->admin);
        $this->postJson('/api/admin/advertisements', [])->assertForbidden();
        $this->getJson('/api/admin/settings')->assertForbidden();
        $this->getJson('/api/admin/email-templates')->assertForbidden();
        $this->getJson('/api/admin/logs/audit')->assertForbidden();
        $this->actingAs($this->super)->getJson('/api/admin/logs/audit')->assertOk();
    }

    public function test_audit_log_is_append_only(): void
    {
        $entry = AuditLog::firstOrFail();
        $this->expectException(LogicException::class);
        $entry->update(['action' => 'tampered']);
    }

    public function test_deactivated_user_loses_access(): void
    {
        $this->admin->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin->fresh())->getJson('/api/admin/applications')->assertUnauthorized();
    }
}
