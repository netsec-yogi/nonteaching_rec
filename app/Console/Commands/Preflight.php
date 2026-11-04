<?php

namespace App\Console\Commands;

use App\Mail\Pool\MailPool;
use App\Models\EmailTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Maintenance\BackupKeys;
use App\Services\Maintenance\BackupService;
use App\Services\Maintenance\MySqlTools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class Preflight extends Command
{
    protected $signature = 'recruitment:preflight {--production : Apply go-live checks}';

    protected $description = 'Check configuration and readiness before go-live';

    public function handle(): int
    {
        $failed = 0;
        $check = function (string $label, bool $ok, string $hint = '') use (&$failed) {
            $this->line(($ok ? '<info>  ✓ </info>' : '<error>  ✗ </error>').' '.$label.(! $ok && $hint ? " — $hint" : ''));
            $failed += $ok ? 0 : 1;
        };

        try {
            DB::select('select 1');
            $check('Database reachable', true);
        } catch (Throwable $e) {
            $check('Database reachable', false, $e->getMessage());
        }
        $check('APP_KEY set', (bool) config('app.key'), 'php artisan key:generate');
        $disk = config('recruitment.documents_disk');
        $check("Documents disk [$disk] writable", rescue(fn () => Storage::disk($disk)->put('.preflight', 'ok') && Storage::disk($disk)->delete('.preflight'), false, false));
        $check('Documents disk is not publicly served', ! (config("filesystems.disks.$disk.url") || config("filesystems.disks.$disk.serve")), 'disable serve/url on this disk');
        $check('Email templates seeded', EmailTemplate::count() >= 15, 'php artisan db:seed');
        $check('A Super Administrator exists', User::whereHas('roles', fn ($q) => $q->where('slug', Role::SUPER_ADMIN))->where('is_active', true)->exists());
        $check('Queue is not synchronous', config('queue.default') !== 'sync', 'set QUEUE_CONNECTION=redis or database and run a worker');
        $mysql = app(MySqlTools::class);
        $check('Backup tools installed (mysqldump, mysql)', $mysql->available('dump') && $mysql->available('mysql'), 'install the MySQL client (default-mysql-client) or set BACKUP_MYSQLDUMP / BACKUP_MYSQL');
        $check('Backup directory writable and outside public/', rescue(function () {
            $dir = app(BackupService::class)->directory();

            return is_writable($dir);
        }, false, false), 'check BACKUP_PATH');
        $check('Backup encryption key available', ! config('maintenance.backup.encrypt', true) || rescue(fn () => (bool) app(BackupKeys::class)->current(), false, false), 'BACKUP_ENCRYPTION_KEY must be base64: and 32 bytes');
        $misconfigured = collect(app(MailPool::class)->accounts())->filter(fn ($a) => $a->enabled && $a->problems);
        $check('Outgoing mail accounts are valid', $misconfigured->isEmpty(),
            $misconfigured->map(fn ($a) => "{$a->name}: ".implode(' ', $a->problems))->implode('; ').' (php artisan mail:pool)');

        if ($this->option('production')) {
            $check('APP_ENV=production', app()->environment('production'));
            $check('APP_DEBUG=false', ! config('app.debug'));
            $check('APP_URL uses https', str_starts_with(config('app.url'), 'https://'));
            $check('Secure session cookie', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE=true');
            $host = parse_url(config('app.url'), PHP_URL_HOST);
            $port = parse_url(config('app.url'), PHP_URL_PORT);
            $stateful = array_map('trim', config('sanctum.stateful', []));
            $check('APP_URL host is a Sanctum stateful domain', in_array($port ? "$host:$port" : $host, $stateful, true),
                'add '.($port ? "$host:$port" : $host).' to SANCTUM_STATEFUL_DOMAINS (sign-in fails otherwise)');
            $check('Mail is not the log driver', ! in_array(config('mail.default'), ['log', 'array'], true));
            $check('No demo accounts active', ! User::where('email', 'like', '%@example.com')->orWhere('email', 'superadmin@iiitm.ac.in')->where('is_active', true)->exists(), 'deactivate seeded demo users');
            $check('Backup directory configured', is_dir(config('maintenance.backup.path')) || @mkdir(config('maintenance.backup.path'), 0700, true));
        }

        $failed ? $this->error("$failed check(s) failed.") : $this->info('All checks passed.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
