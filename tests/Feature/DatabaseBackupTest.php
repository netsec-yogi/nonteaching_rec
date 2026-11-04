<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BackupRestore;
use App\Models\DatabaseBackup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffAlert;
use App\Services\Maintenance\BackupKeys;
use App\Services\Maintenance\BackupReader;
use App\Services\Maintenance\BackupSchedule;
use App\Services\Maintenance\BackupService;
use App\Services\Maintenance\BackupWriter;
use App\Services\Maintenance\MySqlTools;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FakeMySqlTools;
use Tests\TestCase;

/** System maintenance → Database backups: creation, storage, download, retention, schedule, restore. */
class DatabaseBackupTest extends TestCase
{
    private FakeMySqlTools $mysql;

    private string $dir;

    private User $root;

    private User $recruiter;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->dir = storage_path('framework/testing/backups-'.Str::lower(Str::random(8)));
        // Maintenance mode in memory, so a test never takes the development server down.
        config(['maintenance.backup.path' => $this->dir, 'app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->mysql = new FakeMySqlTools;
        $this->app->instance(MySqlTools::class, $this->mysql);
        $this->root = $this->staff(Role::SUPER_ADMIN, 'root@iiitm.test');
        $this->recruiter = $this->staff(Role::RECRUITMENT_ADMIN, 'ra@iiitm.test');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function backup(string $type = 'manual'): DatabaseBackup
    {
        $service = app(BackupService::class);

        return $service->run($service->request($type, $this->root));
    }

    private function path(DatabaseBackup $backup): string
    {
        return $this->dir.'/'.$backup->filename;
    }

    private function grant(string ...$permissions): void
    {
        $role = Role::where('slug', Role::RECRUITMENT_ADMIN)->first();
        $role->permissions()->syncWithoutDetaching(Permission::whereIn('slug', $permissions)->pluck('id'));
        $this->recruiter->forgetPermissionCache();
    }

    private function restorePayload(DatabaseBackup $backup, array $extra = []): array
    {
        return array_merge(['reason' => 'Recover from an accidental bulk change', 'confirmation' => $backup->identifier, 'password' => 'Password@123', 'acknowledge' => true], $extra);
    }

    // ------------------------------------------------------------------ creating

    public function test_super_admin_creates_an_encrypted_verified_backup_in_private_storage(): void
    {
        $response = $this->actingAs($this->root)->postJson('/api/admin/maintenance/backups', ['note' => 'Before the upgrade'])->assertStatus(202);
        $backup = DatabaseBackup::sole();

        $this->assertSame('completed', $response->json('backup.status'));
        $this->assertSame(['manual', $this->root->id, $this->root->name, 'Before the upgrade'], [$backup->type, $backup->initiated_by, $backup->initiated_by_name, $backup->note]);
        $this->assertMatchesRegularExpression('/^BK-\d{8}-\d{6}-[A-Z0-9]{4}$/', $backup->identifier);
        $this->assertTrue($backup->encrypted);
        $this->assertNotNull($backup->verified_at);

        $path = $this->path($backup);
        $this->assertFileExists($path);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame($backup->size_bytes, filesize($path));
        $this->assertSame(hash_file('sha256', $path), $backup->checksum_sha256);
        $this->assertStringStartsWith(BackupWriter::MAGIC, file_get_contents($path));
        $this->assertStringNotContainsString('INSERT INTO `widgets`', file_get_contents($path), 'stored encrypted');
        $this->assertFalse(str_starts_with(realpath($path), realpath(public_path())), 'backups are outside the web root');
        $this->assertStringNotContainsString($this->dir, $response->getContent());
        $this->assertStringNotContainsString('"path"', $response->getContent());

        $sql = implode('', iterator_to_array((new BackupReader($path, app(BackupKeys::class)))->sql(), false));
        $this->assertStringContainsString('INSERT INTO `widgets` VALUES (1),(2);', $sql);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `sessions`', $sql, 'operational tables keep their structure');
        $this->assertEqualsCanonicalizing(config('maintenance.backup.operational_tables'), $this->mysql->skipped, 'but not their rows');
        $this->assertContains('2026_11_04_000100_create_system_maintenance_tables', $backup->manifest['migrations']);

        $this->assertSame(['backup.requested', 'backup.completed'], AuditLog::where('auditable_type', 'database_backup')->orderBy('id')->pluck('action')->all());
        Notification::assertSentTo($this->root, StaffAlert::class, fn ($n) => $n->title === 'Database backup completed');
    }

    public function test_only_one_backup_or_restore_runs_at_a_time(): void
    {
        $running = app(BackupService::class)->request('manual', $this->root);
        DatabaseBackup::whereKey($running->id)->update(['status' => 'running', 'heartbeat_at' => now()]);

        $this->actingAs($this->root)->postJson('/api/admin/maintenance/backups')->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, $running->identifier));

        // An operation that stopped reporting progress no longer blocks.
        DatabaseBackup::whereKey($running->id)->update(['heartbeat_at' => now()->subHour()]);
        $this->postJson('/api/admin/maintenance/backups')->assertStatus(202);
    }

    public function test_a_failed_dump_is_recorded_and_reported_without_secrets_or_files(): void
    {
        config(['database.connections.mysql.password' => 'Db-Pa55word!']);
        $this->mysql->dumpError = 'mysqldump: Got error: 1045: Access denied (using password: YES) with Db-Pa55word! password=hunter22';

        $this->actingAs($this->root)->postJson('/api/admin/maintenance/backups')->assertStatus(202)->assertJsonPath('backup.status', 'failed');
        $backup = DatabaseBackup::sole();

        $this->assertNull($backup->filename);
        $this->assertStringContainsString('Access denied', $backup->error);
        $this->assertStringNotContainsString('Db-Pa55word!', $backup->error);
        $this->assertStringNotContainsString('hunter22', $backup->error);
        $this->assertSame([], array_values(array_diff(scandir($this->dir), ['.', '..'])), 'no partial file left');
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.failed', 'auditable_id' => $backup->id]);
        Notification::assertSentTo($this->root, StaffAlert::class, fn ($n) => $n->title === 'Database backup failed');
    }

    public function test_a_dump_without_the_completion_marker_is_not_accepted(): void
    {
        $this->mysql->dumpSql = "CREATE TABLE `widgets` (`id` int);\nINSERT INTO `widgets` VALUES (1";
        $backup = $this->backup();

        $this->assertSame('failed', $backup->status);
        $this->assertStringContainsString('completion marker', $backup->error);
    }

    // ------------------------------------------------------------------ download and delete

    public function test_downloads_need_their_own_permission_and_are_audited(): void
    {
        $backup = $this->backup();
        $url = "/api/admin/maintenance/backups/{$backup->uuid}/download";

        $this->actingAs($this->recruiter)->getJson('/api/admin/maintenance/backups')->assertForbidden();
        $this->getJson($url)->assertForbidden();

        $this->grant('backups.manage');
        $this->actingAs($this->recruiter->fresh())->getJson('/api/admin/maintenance/backups')->assertOk()->assertJsonPath('data.0.identifier', $backup->identifier);
        $this->getJson($url)->assertForbidden();

        $this->grant('backups.download');
        $response = $this->actingAs($this->recruiter->fresh())->get($url)->assertOk();
        $this->assertSame(file_get_contents($this->path($backup)), file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->assertStringContainsString($backup->filename, $response->headers->get('content-disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.downloaded', 'user_id' => $this->recruiter->id, 'auditable_id' => $backup->id]);
    }

    public function test_deleting_needs_confirmation_and_never_touches_an_operation_in_progress(): void
    {
        $backup = $this->backup();
        $this->actingAs($this->root)->deleteJson("/api/admin/maintenance/backups/{$backup->uuid}")->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $this->assertFileExists($this->path($backup));

        $this->deleteJson("/api/admin/maintenance/backups/{$backup->uuid}", ['confirm' => true, 'reason' => 'Superseded'])->assertOk()->assertJsonPath('backup.status', 'deleted');
        $this->assertFileDoesNotExist($this->path($backup));
        $this->assertSame([$this->root->name, 'Superseded'], [$backup->fresh()->deleted_by_name, $backup->fresh()->deletion_reason]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.deleted', 'auditable_id' => $backup->id]);
        $this->getJson("/api/admin/maintenance/backups/{$backup->uuid}/download")->assertNotFound();

        $running = app(BackupService::class)->request('manual', $this->root);
        DatabaseBackup::whereKey($running->id)->update(['status' => 'running', 'heartbeat_at' => now()]);
        $this->deleteJson("/api/admin/maintenance/backups/{$running->uuid}", ['confirm' => true])->assertStatus(409);

        DatabaseBackup::whereKey($running->id)->update(['status' => 'queued']);
        $this->deleteJson("/api/admin/maintenance/backups/{$running->uuid}", ['confirm' => true])->assertOk()->assertJsonPath('backup.status', 'failed');
        $this->assertStringContainsString('Cancelled', $running->fresh()->error);
    }

    // ------------------------------------------------------------------ retention and schedule

    public function test_retention_removes_old_and_excess_backups_but_never_the_newest_valid_one(): void
    {
        $old = collect([45, 40, 35])->map(function ($days) {
            $b = $this->backup();
            DatabaseBackup::whereKey($b->id)->update(['completed_at' => now()->subDays($days)]);

            return $b;
        });
        app(BackupService::class)->prune();   // 30 days by default: every backup is too old…

        $this->assertSame([true, true, false], $old->map(fn ($b) => $b->fresh()->deleted_at !== null)->all(), '…but the newest valid one stays');
        $this->assertFileExists($this->path($old[2]));
        $this->assertSame('Retention policy', $old[0]->fresh()->deleted_by_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.pruned', 'auditable_id' => $old[0]->id]);

        // A maximum count, applied after each new backup.
        $this->actingAs($this->root)->putJson('/api/admin/maintenance/backup-settings', ['values' => ['enabled' => true, 'frequency' => 'daily', 'time' => '01:30', 'weekday' => 0, 'month_day' => 1, 'retention_days' => 0, 'max_backups' => 2]])->assertOk();
        $new = [$this->backup(), $this->backup()];
        $this->assertSame(2, DatabaseBackup::whereNull('deleted_at')->where('status', 'completed')->count());
        $this->assertNull($new[1]->fresh()->deleted_at);
        $this->assertNotNull($old[2]->fresh()->deleted_at);
    }

    public function test_scheduled_backups_run_once_per_slot_without_anyone_signed_in(): void
    {
        // The first run catches up the latest slot (a baseline right after deployment)…
        $this->travelTo(CarbonImmutable::parse('2026-11-05 01:29'));
        Artisan::call('backup:scheduled');
        $first = DatabaseBackup::sole();
        $this->assertSame(['scheduled', 'completed', 'Scheduler', '2026-11-04 01:30:00'], [$first->type, $first->status, $first->initiated_by_name, $first->schedule_slot->format('Y-m-d H:i:s')]);
        Artisan::call('backup:scheduled');
        $this->assertSame(1, DatabaseBackup::count(), '…and the next one is not due before 01:30');

        $this->travelTo(CarbonImmutable::parse('2026-11-05 01:31'));
        Artisan::call('backup:scheduled');
        Artisan::call('backup:scheduled');
        $this->assertSame(2, DatabaseBackup::count());
        $this->assertSame('2026-11-05 01:30:00', DatabaseBackup::latest('id')->first()->schedule_slot->format('Y-m-d H:i:s'));

        // Missed slots are caught up once, not once per missed day.
        $this->travelTo(CarbonImmutable::parse('2026-11-08 09:00'));
        Artisan::call('backup:scheduled');
        Artisan::call('backup:scheduled');
        $this->assertSame(3, DatabaseBackup::count());
        $this->assertSame('2026-11-08 01:30:00', DatabaseBackup::latest('id')->first()->schedule_slot->format('Y-m-d H:i:s'));

        // Turning the schedule off stops it; a changed schedule does not catch up earlier slots.
        $values = ['enabled' => false, 'frequency' => 'weekly', 'time' => '02:00', 'weekday' => 0, 'month_day' => 1, 'retention_days' => 30, 'max_backups' => 0];
        $this->actingAs($this->root)->putJson('/api/admin/maintenance/backup-settings', ['values' => $values])->assertOk()->assertJsonPath('next_run_at', null);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 03:00'));
        Artisan::call('backup:scheduled');
        $this->assertSame(3, DatabaseBackup::count());

        $this->putJson('/api/admin/maintenance/backup-settings', ['values' => ['enabled' => true] + $values])->assertOk()
            ->assertJsonPath('schedule', 'Every Sunday at 02:00')->assertJsonPath('next_run_at', CarbonImmutable::parse('2026-11-15 02:00')->toIso8601String());
        Artisan::call('backup:scheduled');
        $this->assertSame(3, DatabaseBackup::count(), 'Sunday 8 Nov 02:00 was before the change');
    }

    public function test_schedule_slots_for_each_frequency(): void
    {
        $schedule = app(BackupSchedule::class);
        $at = fn (string $now, array $c) => $schedule->latest(CarbonImmutable::parse($now), $c + ['time' => '01:30', 'weekday' => 0, 'month_day' => 1])->format('Y-m-d H:i');

        $this->assertSame('2026-11-04 01:30', $at('2026-11-04 12:00', ['frequency' => 'daily']));
        $this->assertSame('2026-11-03 01:30', $at('2026-11-04 01:00', ['frequency' => 'daily']));
        $this->assertSame('2026-11-01 01:30', $at('2026-11-04 12:00', ['frequency' => 'weekly']));           // Sunday
        $this->assertSame('2026-11-04 01:30', $at('2026-11-04 12:00', ['frequency' => 'weekly', 'weekday' => 3]));
        $this->assertSame('2026-10-28 01:30', $at('2026-11-04 01:00', ['frequency' => 'weekly', 'weekday' => 3]));
        $this->assertSame('2026-11-01 01:30', $at('2026-11-04 12:00', ['frequency' => 'monthly']));
        $this->assertSame('2026-10-28 01:30', $at('2026-11-04 12:00', ['frequency' => 'monthly', 'month_day' => 28]));
    }

    public function test_backup_settings_are_validated_audited_and_need_backups_manage(): void
    {
        $this->actingAs($this->recruiter)->getJson('/api/admin/maintenance/backup-settings')->assertForbidden();
        $this->actingAs($this->root)->getJson('/api/admin/maintenance/backup-settings')->assertOk()
            ->assertJsonPath('values.frequency', 'daily')->assertJsonPath('values.time', '01:30')->assertJsonPath('values.retention_days', 30)
            ->assertJsonPath('encrypted', true)->assertJsonMissingPath('values.since');

        $this->putJson('/api/admin/maintenance/backup-settings', ['values' => ['enabled' => true, 'frequency' => 'hourly', 'time' => '25:00', 'weekday' => 9, 'month_day' => 31, 'retention_days' => -1, 'max_backups' => 0]])
            ->assertUnprocessable()->assertJsonValidationErrors(['frequency', 'time', 'weekday', 'month_day', 'retention_days']);
        $this->putJson('/api/admin/maintenance/backup-settings', ['values' => ['enabled' => true, 'frequency' => 'monthly', 'time' => '23:45', 'weekday' => 0, 'month_day' => 15, 'retention_days' => 90, 'max_backups' => 12]])
            ->assertOk()->assertJsonPath('schedule', 'Monthly on day 15 at 23:45');

        $entry = AuditLog::where('action', 'backup.settings_updated')->sole();
        $this->assertEqualsCanonicalizing(['frequency' => 'monthly', 'time' => '23:45', 'month_day' => 15, 'retention_days' => 90, 'max_backups' => 12], $entry->new_values);
        $this->assertSame(['frequency' => 'daily', 'max_backups' => 0, 'month_day' => 1, 'retention_days' => 30, 'time' => '01:30'], collect($entry->old_values)->sortKeys()->all());
    }

    // ------------------------------------------------------------------ integrity and restore

    public function test_an_altered_backup_fails_verification_and_cannot_be_restored(): void
    {
        $backup = $this->backup();
        $this->actingAs($this->root)->postJson("/api/admin/maintenance/backups/{$backup->uuid}/verify")->assertOk()->assertJsonPath('valid', true);

        $bytes = file_get_contents($this->path($backup));
        $bytes[intdiv(strlen($bytes), 2)] = chr(ord($bytes[intdiv(strlen($bytes), 2)]) ^ 1);
        file_put_contents($this->path($backup), $bytes);

        $this->postJson("/api/admin/maintenance/backups/{$backup->uuid}/verify")->assertOk()->assertJsonPath('valid', false)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'checksum'));
        $checks = collect($this->getJson("/api/admin/maintenance/backups/{$backup->uuid}/restore-check")->assertOk()->json('checks'));
        $this->assertSame('fail', $checks->firstWhere('key', 'checksum')['status']);

        $this->postJson("/api/admin/maintenance/backups/{$backup->uuid}/restore", $this->restorePayload($backup))->assertUnprocessable()->assertJsonValidationErrors('backup');
        $this->assertSame([], $this->mysql->loaded);
        $this->assertSame(0, BackupRestore::count());
    }

    public function test_super_admin_restores_after_a_safety_backup_in_maintenance_mode(): void
    {
        $backup = $this->backup();
        $this->mysql->dumpSql = str_replace('(1),(2)', '(1),(2),(3)', $this->mysql->dumpSql);  // the "current" database differs

        $response = $this->actingAs($this->root)->postJson("/api/admin/maintenance/backups/{$backup->uuid}/restore", $this->restorePayload($backup))->assertStatus(202);
        $restore = BackupRestore::sole();
        $this->assertSame('completed', $response->json('restore.status'), (string) $restore->error);
        $this->assertSame(['completed', 'done', $this->root->name], [$restore->status, $restore->stage, $restore->requested_by_name]);

        $safety = $restore->safetyBackup;
        $this->assertSame(['safety', 'completed', $restore->id], [$safety->type, $safety->status, $safety->restore_id]);
        $this->assertStringContainsString('(1),(2),(3)', implode('', iterator_to_array((new BackupReader($this->path($safety), app(BackupKeys::class)))->sql(), false)), 'the safety backup holds the data being replaced');

        $this->assertCount(1, $this->mysql->loaded);
        $sql = $this->mysql->loaded[0];
        $this->assertStringStartsWith('SET FOREIGN_KEY_CHECKS=0;', $sql);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `users`;', $sql);
        foreach (config('maintenance.backup.operational_tables') as $table) {
            $this->assertStringNotContainsString("DROP TABLE IF EXISTS `$table`;", $sql, "$table is left as it is");
        }
        $this->assertStringContainsString('INSERT INTO `widgets` VALUES (1),(2);', $sql);
        $this->assertSame([true], $this->mysql->downDuringLoad, 'nobody writes while the data is replaced');
        $this->assertFalse(app()->isDownForMaintenance(), 'the portal reopens afterwards');

        $this->assertSame([], collect($restore->health)->where('status', 'fail')->all());
        $this->assertSame('pass', collect($restore->checks)->firstWhere('key', 'integrity')['status']);
        $audit = AuditLog::where('action', 'backup.restored')->sole();
        $this->assertSame('Recover from an accidental bulk change', $audit->meta['reason']);
        $this->assertSame($safety->identifier, $audit->meta['safety_backup']);
        Notification::assertSentTo($this->root, StaffAlert::class, fn ($n) => $n->title === 'Database restored');

        $this->getJson("/api/admin/maintenance/restores/{$restore->uuid}")->assertOk()->assertJsonPath('restore.status', 'completed')->assertJsonPath('maintenance_mode', false);
    }

    public function test_restoring_needs_its_permission_the_password_and_the_typed_backup_id(): void
    {
        $backup = $this->backup();
        $url = "/api/admin/maintenance/backups/{$backup->uuid}/restore";

        $this->grant('backups.manage', 'backups.download');
        $this->actingAs($this->recruiter->fresh())->postJson($url, $this->restorePayload($backup))->assertForbidden();
        $this->getJson("/api/admin/maintenance/backups/{$backup->uuid}/restore-check")->assertForbidden();

        $this->actingAs($this->root);
        $this->postJson($url, $this->restorePayload($backup, ['password' => 'wrong-password']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restore_denied', 'user_id' => $this->root->id]);
        $this->postJson($url, $this->restorePayload($backup, ['confirmation' => 'yes']))->assertUnprocessable()->assertJsonValidationErrors('confirmation');
        $this->postJson($url, $this->restorePayload($backup, ['reason' => 'short']))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson($url, $this->restorePayload($backup, ['acknowledge' => false]))->assertUnprocessable()->assertJsonValidationErrors('acknowledge');

        $this->assertSame([], $this->mysql->loaded);
        $this->assertSame(0, BackupRestore::count());
    }

    public function test_a_failed_restore_rolls_back_to_the_safety_backup(): void
    {
        $backup = $this->backup();
        $this->mysql->onLoad = function (string $sql, int $call) {
            if ($call === 1) {
                throw new RuntimeException('mysql failed (exit 1): ERROR 1064 at line 9: You have an error in your SQL syntax');
            }
        };

        $this->actingAs($this->root)->postJson("/api/admin/maintenance/backups/{$backup->uuid}/restore", $this->restorePayload($backup))->assertStatus(202);
        $restore = BackupRestore::sole();

        $this->assertSame(['failed', 'succeeded', false], [$restore->status, $restore->rollback_status, $restore->maintenance_left_on]);
        $this->assertStringContainsString('ERROR 1064', $restore->error);
        $this->assertStringContainsString('rolled back to the safety backup', $restore->error);
        $this->assertCount(2, $this->mysql->loaded);
        $this->assertStringContainsString('backup '.$restore->safetyBackup->identifier, $this->mysql->loaded[1], 'the second load is the safety backup');
        $this->assertFalse(app()->isDownForMaintenance());
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.restore_failed', 'auditable_id' => $backup->id]);
        Notification::assertSentTo($this->root, StaffAlert::class, fn ($n) => $n->title === 'Database restore failed');
    }

    public function test_when_the_rollback_also_fails_the_portal_stays_in_maintenance_mode(): void
    {
        $backup = $this->backup();
        $this->mysql->onLoad = fn () => throw new RuntimeException('mysql failed (exit 1): ERROR 2013: Lost connection');

        $this->actingAs($this->root)->postJson("/api/admin/maintenance/backups/{$backup->uuid}/restore", $this->restorePayload($backup))->assertStatus(202);
        $restore = BackupRestore::sole();

        $this->assertSame(['failed', 'failed', true], [$restore->status, $restore->rollback_status, $restore->maintenance_left_on]);
        $this->assertTrue(app()->isDownForMaintenance());
        $this->assertStringContainsString('maintenance mode', $restore->error);
        Artisan::call('up');
    }

    public function test_restoring_an_older_full_dump_leaves_sessions_jobs_and_cache_alone(): void
    {
        // Dumps made by the earlier db:backup include every table, operational ones too.
        $this->mysql->dumpSql = "/*M!999999\\- enable the sandbox mode */ \n-- MariaDB dump 10.19\n".
            "--\n-- Table structure for table `jobs`\n--\n\nDROP TABLE IF EXISTS `jobs`;\nCREATE TABLE `jobs` (`id` int);\n\n".
            "--\n-- Dumping data for table `jobs`\n--\n\nLOCK TABLES `jobs` WRITE;\nINSERT INTO `jobs` VALUES (1);\nUNLOCK TABLES;\n\n".
            "--\n-- Table structure for table `sessions`\n--\n\nDROP TABLE IF EXISTS `sessions`;\nCREATE TABLE `sessions` (`id` varchar(255));\n".
            "INSERT INTO `sessions` VALUES ('old-session');\n\n".
            "--\n-- Table structure for table `widgets`\n--\n\nDROP TABLE IF EXISTS `widgets`;\nCREATE TABLE `widgets` (`id` int);\nINSERT INTO `widgets` VALUES (1),(2);\n".
            "-- Dump completed on 2026-10-31 20:00:00\n";
        $backup = $this->backup();

        $this->actingAs($this->root)->postJson("/api/admin/maintenance/backups/{$backup->uuid}/restore", $this->restorePayload($backup))->assertStatus(202);
        $this->assertSame('completed', BackupRestore::sole()->status, (string) BackupRestore::sole()->error);

        $sql = $this->mysql->loaded[0];
        $this->assertStringContainsString('INSERT INTO `widgets` VALUES (1),(2);', $sql);
        foreach (['INSERT INTO `jobs`', 'DROP TABLE IF EXISTS `jobs`', 'INSERT INTO `sessions`', 'DROP TABLE IF EXISTS `sessions`', 'enable the sandbox mode'] as $gone) {
            $this->assertStringNotContainsString($gone, $sql);
        }
        $this->assertStringContainsString('-- Dump completed', $sql);
    }

    public function test_a_backup_from_a_newer_version_of_the_portal_is_refused(): void
    {
        $backup = $this->backup();
        $manifest = $backup->manifest;
        $manifest['migrations'][] = '2027_01_01_000000_from_the_future';
        $backup->forceFill(['manifest' => $manifest])->save();

        $checks = collect($this->actingAs($this->root)->getJson("/api/admin/maintenance/backups/{$backup->uuid}/restore-check")->assertOk()->json('checks'));
        $this->assertSame('fail', $checks->firstWhere('key', 'schema')['status']);
        $this->postJson("/api/admin/maintenance/backups/{$backup->uuid}/restore", $this->restorePayload($backup))->assertUnprocessable();
        $this->assertSame([], $this->mysql->loaded);
    }

    public function test_interrupted_operations_are_recorded_as_failed(): void
    {
        $backup = $this->backup();
        $stuck = app(BackupService::class)->request('manual', $this->root);
        DatabaseBackup::whereKey($stuck->id)->update(['status' => 'running', 'heartbeat_at' => now()->subHour()]);
        $restore = BackupRestore::create(['database_backup_id' => $backup->id, 'status' => 'running', 'stage' => 'restoring', 'reason' => 'Test restore reason', 'queued_at' => now()->subHour(), 'heartbeat_at' => now()->subHour()]);

        Artisan::call('maintenance:sweep');

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('failed', $restore->fresh()->status);
        $this->assertStringContainsString('maintenance mode', $restore->fresh()->error);
        $this->assertSame('completed', $backup->fresh()->status);
    }

    public function test_overview_reports_backup_figures_to_backup_managers_only(): void
    {
        $backup = $this->backup();
        $this->actingAs($this->root)->getJson('/api/admin/maintenance/overview')->assertOk()
            ->assertJsonPath('backups.available', 1)->assertJsonPath('backups.latest.identifier', $backup->identifier)
            ->assertJsonPath('backups.schedule', 'Daily at 01:30')->assertJsonPath('recent_backups.0.identifier', $backup->identifier);

        $this->actingAs($this->recruiter)->getJson('/api/admin/maintenance/overview')->assertForbidden();
        $this->grant('logs.view');
        $this->actingAs($this->recruiter->fresh())->getJson('/api/admin/maintenance/overview')->assertOk()->assertJsonPath('backups', null)->assertJsonPath('recent_backups', []);
    }

    /** The real mysqldump against the test database (skipped where the client is not installed). */
    public function test_a_real_mysqldump_backup_of_the_test_database(): void
    {
        $real = new MySqlTools;
        if (! $real->available('dump')) {
            $this->markTestSkipped('mysqldump is not installed.');
        }
        $this->app->instance(MySqlTools::class, $real);

        $backup = $this->backup('command');
        $this->assertSame('completed', $backup->status, (string) $backup->error);
        $sql = implode('', iterator_to_array((new BackupReader($this->path($backup), app(BackupKeys::class)))->sql(), false));
        $this->assertStringContainsString('CREATE TABLE `applications`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `sessions`', $sql);
        $this->assertStringNotContainsString('INSERT INTO `database_backups`', $sql);
        $this->assertMatchesRegularExpression('/-- Dump completed[^\n]*\s*$/', $sql);
    }
}
