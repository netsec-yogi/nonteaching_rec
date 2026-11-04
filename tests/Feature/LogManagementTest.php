<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\StaffAlert;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/** System maintenance → Application logs: listing, viewing, masking, download, archive, retention. */
class LogManagementTest extends TestCase
{
    private string $dir;

    private User $root;

    private User $recruiter;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->dir = storage_path('framework/testing/logs-'.Str::lower(Str::random(8)));
        File::ensureDirectoryExists($this->dir);
        config([
            'maintenance.logs.path' => $this->dir,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.path' => $this->dir.'/laravel.log',
        ]);
        $this->root = $this->staff(Role::SUPER_ADMIN, 'root@iiitm.test');
        $this->recruiter = $this->staff(Role::RECRUITMENT_ADMIN, 'ra@iiitm.test');
        $this->today = 'laravel-'.now()->format('Y-m-d').'.log';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::delete($this->outside());
        parent::tearDown();
    }

    private function outside(): string
    {
        return storage_path('framework/testing/outside-'.basename($this->dir).'.log');
    }

    private function write(string $name, string $content, ?int $daysOld = null): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $content);
        if ($daysOld !== null) {
            touch($path, now()->subDays($daysOld)->getTimestamp());
        }

        return $path;
    }

    private function entry(string $time, string $level, string $message, string $extra = ''): string
    {
        return "[$time] production.$level: $message".($extra !== '' ? "\n$extra" : '')."\n";
    }

    private function grant(string ...$permissions): void
    {
        Role::where('slug', Role::RECRUITMENT_ADMIN)->first()->permissions()->syncWithoutDetaching(Permission::whereIn('slug', $permissions)->pluck('id'));
        $this->recruiter->forgetPermissionCache();
    }

    private function fixture(): void
    {
        $trace = "[stacktrace]\n#0 /var/www/vendor/laravel/framework/src/Illuminate/Database/Connection.php(838): PDOStatement->execute()\n#1 {main}\n\"} ";
        $this->write('laravel-2026-10-01.log',
            $this->entry('2026-10-01 08:00:00', 'INFO', 'Scheduled reminders sent').
            $this->entry('2026-10-01 09:15:00', 'ERROR', 'SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry', '{"exception":"[object] (Illuminate\\\\Database\\\\QueryException(code: 23000): SQLSTATE[23000] at /var/www/vendor/laravel/framework/src/Illuminate/Database/Connection.php:838)'."\n".$trace).
            $this->entry('2026-10-01 10:30:00', 'WARNING', 'SMTP account resting: Expected response code 250 but got 421').
            $this->entry('2026-10-01 11:45:00', 'CRITICAL', 'SQLSTATE[HY000] [2002] Connection refused').
            $this->entry('2026-10-01 12:00:00', 'ERROR', 'Login failed {"email":"x@example.com","password":"hunter2pass","_token":"abc123token"}').
            $this->entry('2026-10-01 13:00:00', 'DEBUG', 'Request headers Authorization: Bearer abcdefghijklmnop1234 cookie=laravel_session%3Dzzzz'),
            daysOld: 30);
    }

    // ------------------------------------------------------------------ listing and viewing

    public function test_files_are_listed_with_sizes_and_active_ones_flagged_without_paths(): void
    {
        $this->fixture();
        $this->write($this->today, $this->entry(now()->format('Y-m-d H:i:s'), 'INFO', 'Today'));
        $this->write('worker.log', "2026-11-04 10:00:00 App\\Jobs\\SendTemplatedEmail RUNNING\n");

        $response = $this->actingAs($this->root)->getJson('/api/admin/maintenance/logs')->assertOk();
        $files = collect($response->json('files'))->keyBy('name');

        $this->assertEqualsCanonicalizing(['laravel-2026-10-01.log', $this->today, 'worker.log'], $files->keys()->all());
        $this->assertFalse($files['laravel-2026-10-01.log']['active']);
        $this->assertSame('2026-10-01', $files['laravel-2026-10-01.log']['date']);
        $this->assertTrue($files[$this->today]['active']);
        $this->assertStringContainsString('daily', $files[$this->today]['active_reason']);
        $this->assertTrue($files['worker.log']['active'], 'written to recently');
        $this->assertSame(filesize($this->dir.'/laravel-2026-10-01.log'), $files['laravel-2026-10-01.log']['size']);
        $this->assertSame(3, $response->json('storage.file_count'));
        $this->assertStringNotContainsString($this->dir, $response->getContent());
    }

    public function test_entries_come_newest_first_in_pages_without_gaps_or_repeats(): void
    {
        $content = '';
        for ($i = 1; $i <= 2500; $i++) {
            $content .= $this->entry(now()->subDays(3)->startOfDay()->addSeconds($i)->format('Y-m-d H:i:s'), $i % 10 ? 'INFO' : 'ERROR', "Event $i", "  detail line for $i");
        }
        $this->write('laravel-big.log', $content, daysOld: 3);

        $seen = [];
        $cursor = null;
        $this->actingAs($this->root);
        do {
            $page = $this->getJson('/api/admin/maintenance/logs/entries?'.http_build_query(array_filter(['file' => 'laravel-big.log', 'limit' => 200, 'cursor' => $cursor], fn ($v) => $v !== null)))->assertOk();
            foreach ($page->json('entries') as $e) {
                $seen[] = (int) substr($e['message'], 6);
            }
            $cursor = $page->json('next_cursor');
        } while ($cursor !== null);

        $this->assertSame(range(2500, 1), $seen);
        $first = $this->getJson('/api/admin/maintenance/logs/entries?file=laravel-big.log&limit=1')->json('entries.0');
        $this->assertSame(['error', 'Event 2500', 'detail line for 2500'], [$first['level'], $first['message'], trim($first['details'])]);
    }

    public function test_a_search_stops_at_the_byte_limit_and_continues_from_there(): void
    {
        config(['maintenance.logs.scan_bytes' => 1]);   // one 256 KiB block per request
        $content = $this->entry('2026-10-01 00:00:00', 'ERROR', 'The needle at the very start');
        for ($i = 0; $i < 6000; $i++) {
            $content .= $this->entry('2026-10-01 01:00:00', 'INFO', "Filler entry number $i with some padding text to take up space");
        }
        $this->write('laravel-2026-10-01.log', $content, daysOld: 30);

        $this->actingAs($this->root);
        $requests = 0;
        $cursor = null;
        $found = [];
        do {
            $page = $this->getJson('/api/admin/maintenance/logs/entries?'.http_build_query(array_filter(['file' => 'laravel-2026-10-01.log', 'q' => 'needle', 'cursor' => $cursor], fn ($v) => $v !== null)))->assertOk();
            $requests++;
            $found = array_merge($found, $page->json('entries'));
            if ($page->json('next_cursor') !== null) {
                $this->assertTrue($page->json('limited'));
                $this->assertLessThanOrEqual(262144, $page->json('scanned_bytes'));
            }
            $cursor = $page->json('next_cursor');
        } while ($cursor !== null && $requests < 20);

        $this->assertGreaterThan(1, $requests);
        $this->assertSame(['The needle at the very start'], array_column($found, 'message'));
    }

    public function test_entries_filter_by_level_keyword_exception_category_and_time(): void
    {
        $this->fixture();
        $this->actingAs($this->root);
        $get = fn (array $q) => collect($this->getJson('/api/admin/maintenance/logs/entries?'.http_build_query(['file' => 'laravel-2026-10-01.log'] + $q))->assertOk()->json('entries'));

        $this->assertSame(['critical', 'error', 'error'], $get(['levels' => ['error', 'critical']])->pluck('level')->sort()->values()->all());
        $this->assertSame(['2026-10-01 10:30:00'], $get(['q' => 'expected RESPONSE'])->pluck('timestamp')->all());
        $this->assertSame(['Illuminate\\Database\\QueryException'], $get(['exception' => 'QueryException'])->pluck('exception')->all());
        $this->assertSame(['2026-10-01 11:45:00'], $get(['category' => 'database_connection'])->pluck('timestamp')->all());
        $this->assertSame('sql', $get(['exception' => 'QueryException'])->first()['category']);
        $this->assertSame('mail', $get(['q' => 'SMTP'])->first()['category']);
        $this->assertSame(['2026-10-01 11:45:00', '2026-10-01 10:30:00'], $get(['from' => '2026-10-01T10:00', 'to' => '2026-10-01T11:45'])->pluck('timestamp')->all());
        $this->assertStringContainsString('#0 /var/www/vendor', $get(['exception' => 'QueryException'])->first()['details'], 'stack trace');

        $this->getJson('/api/admin/maintenance/logs/entries?file=laravel-2026-10-01.log&levels[]=fatal')->assertUnprocessable();
    }

    public function test_secrets_and_identity_numbers_are_masked_in_the_viewer_but_downloads_are_original(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $secret = 'password=hunter2pass token: 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef APP_KEY='.config('app.key').
            ' Aadhaar 2345 6789 0124 PAN ABCDE1234F hash $2y$12$'.str_repeat('a', 53).' dsn mysql://ntrec:SuperSecret9@db:3306/ntrec Bearer eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.c2lnbmF0dXJl';
        $this->write('laravel-2026-10-02.log', $this->entry('2026-10-02 10:00:00', 'ERROR', $secret, '{"password":"another-secret","api_key":"live_abc123"}'), daysOld: 30);

        $this->grant('logs.view', 'logs.download');
        $this->actingAs($this->recruiter->fresh());
        $body = $this->getJson('/api/admin/maintenance/logs/entries?file=laravel-2026-10-02.log')->assertOk()->getContent();
        foreach (['hunter2pass', '0123456789abcdef0123456789abcdef', str_repeat('k', 32), base64_encode(str_repeat('k', 32)), '2345 6789', 'ABCDE1', str_repeat('a', 53), 'SuperSecret9', 'eyJhbGciOiJIUzI1NiJ9', 'another-secret', 'live_abc123'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "$leak must be masked");
        }
        $this->assertStringContainsString('XXXX XXXX 0124', $body);
        $this->assertStringContainsString('XXXXXX234F', $body);
        $this->assertStringContainsString('[redacted]', $body);

        $this->assertDatabaseHas('audit_logs', ['action' => 'logs.viewed', 'user_id' => $this->recruiter->id]);
        $response = $this->get('/api/admin/maintenance/logs/download?file=laravel-2026-10-02.log')->assertOk();
        $this->assertStringContainsString('hunter2pass', file_get_contents($response->baseResponse->getFile()->getPathname()), 'downloads are the original file');
        $this->assertDatabaseHas('audit_logs', ['action' => 'logs.downloaded', 'user_id' => $this->recruiter->id]);
    }

    public function test_names_outside_the_log_directory_are_refused(): void
    {
        $this->fixture();
        File::put($this->outside(), 'secret outside');
        symlink($this->outside(), $this->dir.'/linked.log');
        $this->actingAs($this->root);

        foreach (['../.env', '../../.env.log', '/etc/passwd', 'laravel-2026-10-01.log/../x.log', '.hidden.log', 'laravel.txt', "laravel\0-2026-10-01.log"] as $name) {
            $this->getJson('/api/admin/maintenance/logs/entries?'.http_build_query(['file' => $name]))->assertUnprocessable();
            $this->getJson('/api/admin/maintenance/logs/download?'.http_build_query(['file' => $name]))->assertUnprocessable();
        }
        $this->getJson('/api/admin/maintenance/logs/download?file=linked.log')->assertNotFound();
        $this->getJson('/api/admin/maintenance/logs/download?file=missing.log')->assertNotFound();
        $this->getJson('/api/admin/maintenance/logs/download?kind=archive&file=../laravel-2026-10-01.log.gz')->assertUnprocessable();
        $this->assertNotContains('linked.log', collect($this->getJson('/api/admin/maintenance/logs')->json('files'))->pluck('name')->all());
    }

    // ------------------------------------------------------------------ download, archive, delete

    public function test_selected_files_download_as_one_zip(): void
    {
        $this->fixture();
        $this->write('laravel-2026-10-03.log', $this->entry('2026-10-03 10:00:00', 'INFO', 'Third'), daysOld: 20);
        $this->actingAs($this->root)->postJson('/api/admin/maintenance/logs/archive', ['files' => ['laravel-2026-10-03.log']])->assertOk();

        $response = $this->get('/api/admin/maintenance/logs/download-zip?'.http_build_query(['files' => ['laravel-2026-10-01.log'], 'archives' => ['laravel-2026-10-03.log.gz']]))->assertOk();
        $this->assertSame('application/zip', $response->headers->get('content-type'));
        $zip = new ZipArchive;
        $zip->open($response->baseResponse->getFile()->getPathname());
        $this->assertSame(file_get_contents($this->dir.'/laravel-2026-10-01.log'), $zip->getFromName('laravel-2026-10-01.log'));
        $this->assertNotFalse($zip->getFromName('archive/laravel-2026-10-03.log.gz'));
        $zip->close();
        @unlink($response->baseResponse->getFile()->getPathname());
        $this->assertSame('zip', AuditLog::where('action', 'logs.downloaded')->latest('id')->first()->new_values['kind']);
    }

    public function test_archiving_compresses_inactive_files_and_refuses_active_ones(): void
    {
        $this->fixture();
        $original = file_get_contents($this->dir.'/laravel-2026-10-01.log');
        $mtime = filemtime($this->dir.'/laravel-2026-10-01.log');
        $this->write($this->today, $this->entry(now()->format('Y-m-d H:i:s'), 'INFO', 'Today'));

        $result = $this->actingAs($this->root)->postJson('/api/admin/maintenance/logs/archive', ['files' => ['laravel-2026-10-01.log', $this->today]])->assertOk();

        $this->assertSame(['laravel-2026-10-01.log'], array_column($result->json('done'), 'name'));
        $this->assertStringContainsString('in use', $result->json('failed.0.error'));
        $this->assertFileDoesNotExist($this->dir.'/laravel-2026-10-01.log');
        $this->assertFileExists($this->dir.'/'.$this->today);
        $archive = $this->dir.'/archive/laravel-2026-10-01.log.gz';
        $this->assertSame($original, gzdecode(file_get_contents($archive)));
        $this->assertSame($mtime, filemtime($archive), 'keeps the original date for retention');
        $this->assertDatabaseHas('audit_logs', ['action' => 'logs.archived', 'user_id' => $this->root->id]);

        $this->get('/api/admin/maintenance/logs/download?kind=archive&file=laravel-2026-10-01.log.gz')->assertOk()->assertHeader('content-type', 'application/gzip');
    }

    public function test_deleting_needs_confirmation_and_never_removes_an_active_log(): void
    {
        $this->fixture();
        $this->write($this->today, $this->entry(now()->format('Y-m-d H:i:s'), 'INFO', 'Today'));
        $files = [['kind' => 'log', 'name' => 'laravel-2026-10-01.log'], ['kind' => 'log', 'name' => $this->today]];

        $this->actingAs($this->root)->postJson('/api/admin/maintenance/logs/delete', ['files' => $files])->assertUnprocessable()->assertJsonValidationErrors('confirm');
        $result = $this->postJson('/api/admin/maintenance/logs/delete', ['files' => $files, 'confirm' => true])->assertOk();

        $this->assertSame(['laravel-2026-10-01.log'], array_column($result->json('done'), 'name'));
        $this->assertSame($this->today, $result->json('failed.0.name'));
        $this->assertFileDoesNotExist($this->dir.'/laravel-2026-10-01.log');
        $this->assertFileExists($this->dir.'/'.$this->today);
        $this->assertDatabaseHas('audit_logs', ['action' => 'logs.deleted', 'user_id' => $this->root->id]);
    }

    public function test_daily_maintenance_archives_old_logs_expires_archives_and_warns_on_storage(): void
    {
        $this->fixture();                                                   // 30 days old
        $this->write('laravel-2026-11-01.log', $this->entry('2026-11-01 10:00:00', 'INFO', 'Recent'), daysOld: 3);
        File::ensureDirectoryExists($this->dir.'/archive');
        file_put_contents($this->dir.'/archive/laravel-2025-01-01.log.gz', gzencode(str_repeat('x', 2 * 1048576)));
        touch($this->dir.'/archive/laravel-2025-01-01.log.gz', now()->subDays(400)->getTimestamp());
        file_put_contents($this->dir.'/archive/laravel-2026-09-01.log.gz', gzencode(random_bytes(1200000)));  // incompressible: 1.2 MB
        touch($this->dir.'/archive/laravel-2026-09-01.log.gz', now()->subDays(60)->getTimestamp());

        $this->actingAs($this->root)->putJson('/api/admin/maintenance/log-settings', ['values' => ['auto_archive' => true, 'archive_after_days' => 7, 'retention_days' => 365, 'warn_at_mb' => 1]])->assertOk();
        $this->app['auth']->forgetGuards();   // the scheduler runs with nobody signed in
        Artisan::call('logs:maintain');

        $this->assertFileDoesNotExist($this->dir.'/laravel-2026-10-01.log');
        $this->assertFileExists($this->dir.'/archive/laravel-2026-10-01.log.gz');
        $this->assertFileExists($this->dir.'/laravel-2026-11-01.log', 'younger than 7 days');
        $this->assertFileDoesNotExist($this->dir.'/archive/laravel-2025-01-01.log.gz', 'past retention');
        $this->assertFileExists($this->dir.'/archive/laravel-2026-09-01.log.gz');
        Notification::assertSentTo($this->root, StaffAlert::class, fn ($n) => $n->title === 'Log storage is near its limit');
        $this->assertDatabaseHas('audit_logs', ['action' => 'logs.auto_archived', 'actor_type' => 'system']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'logs.pruned', 'actor_type' => 'system']);

        Artisan::call('logs:maintain');
        Notification::assertSentToTimes($this->root, StaffAlert::class, 1);   // once a day
    }

    public function test_log_settings_are_validated_and_audited(): void
    {
        $this->actingAs($this->root)->putJson('/api/admin/maintenance/log-settings', ['values' => ['auto_archive' => true, 'archive_after_days' => 0, 'retention_days' => -5, 'warn_at_mb' => 0]])
            ->assertUnprocessable()->assertJsonValidationErrors(['archive_after_days', 'retention_days', 'warn_at_mb']);
        $this->putJson('/api/admin/maintenance/log-settings', ['values' => ['auto_archive' => false, 'archive_after_days' => 10, 'retention_days' => 0, 'warn_at_mb' => 2048]])
            ->assertOk()->assertJsonPath('values.auto_archive', false)->assertJsonPath('values.retention_days', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'logs.settings_updated', 'user_id' => $this->root->id]);
    }

    // ------------------------------------------------------------------ permissions and dashboard

    public function test_each_log_action_needs_its_own_permission(): void
    {
        $this->fixture();
        $this->actingAs($this->recruiter);
        $this->getJson('/api/admin/maintenance/logs')->assertForbidden();
        $this->getJson('/api/admin/maintenance/logs/entries?file=laravel-2026-10-01.log')->assertForbidden();

        $this->grant('logs.view');
        $this->actingAs($this->recruiter->fresh());
        $this->getJson('/api/admin/maintenance/logs')->assertOk()->assertJsonPath('can', ['download' => false, 'manage' => false]);
        $this->getJson('/api/admin/maintenance/logs/entries?file=laravel-2026-10-01.log')->assertOk();
        $this->getJson('/api/admin/maintenance/logs/download?file=laravel-2026-10-01.log')->assertForbidden();
        $this->getJson('/api/admin/maintenance/logs/download-zip?files[]=laravel-2026-10-01.log')->assertForbidden();
        $this->postJson('/api/admin/maintenance/logs/archive', ['files' => ['laravel-2026-10-01.log']])->assertForbidden();
        $this->postJson('/api/admin/maintenance/logs/delete', ['files' => [['kind' => 'log', 'name' => 'laravel-2026-10-01.log']], 'confirm' => true])->assertForbidden();
        $this->putJson('/api/admin/maintenance/log-settings', ['values' => []])->assertForbidden();

        $this->grant('logs.manage');
        $this->actingAs($this->recruiter->fresh())->postJson('/api/admin/maintenance/logs/archive', ['files' => ['laravel-2026-10-01.log']])->assertOk()->assertJsonCount(1, 'done');
    }

    public function test_dashboard_counts_todays_errors_and_viewing_is_audited_once(): void
    {
        $t = now()->startOfDay()->addHours(9);
        $this->write($this->today,
            $this->entry($t->format('Y-m-d H:i:s'), 'ERROR', 'Expected response code 250 but got 421 SMTP').
            $this->entry($t->addMinute()->format('Y-m-d H:i:s'), 'ERROR', 'SQLSTATE[23000]: Duplicate entry').
            $this->entry($t->addMinutes(2)->format('Y-m-d H:i:s'), 'INFO', 'Fine').
            $this->entry($t->addMinutes(3)->format('Y-m-d H:i:s'), 'CRITICAL', 'Disk full: No space left on device').
            $this->entry($t->addMinutes(4)->format('Y-m-d H:i:s'), 'ERROR', 'Something else broke'));
        $this->write('laravel.log', $this->entry(now()->subDay()->format('Y-m-d H:i:s'), 'ERROR', 'Yesterday'), daysOld: 1);

        $logs = $this->actingAs($this->root)->getJson('/api/admin/maintenance/overview')->assertOk()->json('logs');
        $this->assertSame([3, 1], [$logs['today']['errors'], $logs['today']['critical']]);
        $this->assertSame(['application' => 1, 'filesystem' => 1, 'mail' => 1, 'sql' => 1], collect($logs['today']['categories'])->sortKeys()->all());
        $this->assertSame($this->today, $logs['today']['latest_entry']['name']);
        $this->assertSame(2, $logs['storage']['file_count']);

        $this->getJson("/api/admin/maintenance/logs/entries?file={$this->today}")->assertOk();
        $this->getJson("/api/admin/maintenance/logs/entries?file={$this->today}&levels[]=error")->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'logs.viewed')->count());
    }

    public function test_files_in_another_format_are_shown_line_by_line(): void
    {
        $this->write('worker.log', "2026-11-04 10:00:00 App\\Jobs\\SendTemplatedEmail ...... RUNNING\n2026-11-04 10:00:01 App\\Jobs\\SendTemplatedEmail ...... 120ms DONE\n", daysOld: 2);

        $entries = $this->actingAs($this->root)->getJson('/api/admin/maintenance/logs/entries?file=worker.log')->assertOk()->assertJsonPath('structured', false)->json('entries');
        $this->assertSame(['DONE', 'RUNNING'], array_map(fn ($e) => substr($e['message'], -4) === 'DONE' ? 'DONE' : 'RUNNING', $entries));
        $this->assertNull($entries[0]['level']);
    }
}
