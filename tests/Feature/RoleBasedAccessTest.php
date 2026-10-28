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

    public function test_advertisement_configuration_is_limited_to_its_administrators(): void
    {
        $role = Role::where('slug', 'recruitment_admin')->first();
        $perms = $role->permissions()->pluck('slug')->push('advertisements.manage')->all();
        $this->actingAs($this->super)->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => $perms])->assertOk();

        $ad = $this->ad->id;
        $this->actingAs($this->admin->fresh());
        $this->getJson("/api/admin/advertisements/{$ad}/requirements")->assertOk();
        $this->getJson("/api/admin/advertisements/{$ad}/stages")->assertOk();

        $this->ad->administrators()->detach($this->admin->id);
        $this->getJson("/api/admin/advertisements/{$ad}/requirements")->assertForbidden();
        $this->postJson("/api/admin/advertisements/{$ad}/requirements", [])->assertForbidden();
        $this->getJson("/api/admin/advertisements/{$ad}/stages")->assertForbidden();
        $this->putJson("/api/admin/advertisements/{$ad}/stages", ['stages' => []])->assertForbidden();
        $this->postJson("/api/admin/advertisements/{$ad}/posts", [])->assertForbidden();
        // Routes in the group without an advertisement are unaffected.
        $this->getJson('/api/admin/cycles')->assertOk();
    }

    public function test_user_management_cannot_grant_or_touch_super_administrator(): void
    {
        $role = Role::where('slug', 'recruitment_admin')->first();
        $perms = $role->permissions()->pluck('slug')->push('users.manage')->all();
        $this->actingAs($this->super)->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => $perms])->assertOk();

        $self = ['name' => $this->admin->name, 'email' => $this->admin->email, 'is_active' => true];
        $this->actingAs($this->admin->fresh());
        // Cannot promote oneself (or anyone) to Super Administrator.
        $this->putJson("/api/admin/users/{$this->admin->id}", $self + ['roles' => ['recruitment_admin', 'super_admin']])->assertUnprocessable()->assertJsonValidationErrors('roles.1');
        $this->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@iiitm.test', 'roles' => ['super_admin']])->assertUnprocessable();
        // Cannot change a Super Administrator's email (and then request their reset link).
        $this->putJson("/api/admin/users/{$this->super->id}", ['name' => 'S', 'email' => 'ra@iiitm.test', 'roles' => ['super_admin']])->assertForbidden();
        $this->postJson("/api/admin/users/{$this->super->id}/reset")->assertForbidden();
        $this->assertFalse($this->admin->fresh()->isSuperAdmin());

        // A Super Administrator still can.
        $this->actingAs($this->super)->putJson("/api/admin/users/{$this->admin->id}", $self + ['roles' => ['recruitment_admin', 'super_admin']])->assertOk();
        $this->assertTrue($this->admin->fresh()->isSuperAdmin());
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

    public function test_log_viewers_are_scoped_to_visible_applications(): void
    {
        $role = Role::where('slug', 'scrutiny_user')->first();
        $perms = $role->permissions()->pluck('slug')->push('audit.view')->push('email_logs.view')->all();
        $this->actingAs($this->super)->putJson("/api/admin/roles/{$role->id}/permissions", ['permissions' => $perms])->assertOk();

        // The scrutiny user is not assigned to this application, so its trail must not appear.
        $this->actingAs($this->scrutiny->fresh());
        foreach (['/api/admin/logs/audit', '/api/admin/logs/access', '/api/admin/logs/emails'] as $url) {
            $rows = $this->getJson($url)->assertOk()->json('data');
            $this->assertEmpty(array_filter($rows, fn ($r) => $r['application_id'] !== null), "$url leaks application entries");
        }
        $this->actingAs($this->super);
        $this->assertNotEmpty(array_filter($this->getJson('/api/admin/logs/audit')->json('data'), fn ($r) => $r['application_id'] === $this->application->id));
    }

    public function test_a_form_template_in_use_cannot_be_deleted(): void
    {
        $id = $this->ad->referee_form_template_id;
        $this->actingAs($this->super)->deleteJson("/api/admin/forms/referee/$id")->assertUnprocessable();
        $this->assertDatabaseHas('referee_form_templates', ['id' => $id, 'deleted_at' => null]);
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
