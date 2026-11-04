<?php

namespace App\Services\Maintenance;

use App\Jobs\RunDatabaseRestore;
use App\Models\BackupRestore;
use App\Models\DatabaseBackup;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Generator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Restoring the database from a backup the portal made. Only catalogued backups can be restored
 * (never an uploaded file), after checks of the file (checksum, authentication, completeness) and
 * of compatibility (engine, server version, schema). Then, in order:
 *
 *   1. a safety backup of the current database (nothing changes if it fails);
 *   2. maintenance mode, so nobody writes while the data is replaced;
 *   3. every table except the operational ones is dropped and the backup is loaded;
 *   4. migrations newer than the backup are applied;
 *   5. health checks (connectivity, tables, migrations, an active Super Administrator).
 *
 * If 3–5 fail, the safety backup is loaded the same way. If that also fails the portal stays in
 * maintenance mode for the server administrator (docs/OPERATIONS.md, "Restore").
 */
class RestoreService
{
    private ?int $lastTick = null;

    public function __construct(
        private BackupService $backups,
        private MySqlTools $mysql,
        private BackupKeys $keys,
        private AuditLogger $audit,
        private MaintenanceNotifier $notifier,
        private MaintenanceOperations $ops,
    ) {}

    /**
     * Checks shown before confirming (quick) and re-run by the restore itself (deep: the whole
     * file is decrypted and decompressed).
     *
     * @return list<array{key: string, label: string, status: string, detail: string|null}>
     */
    public function checks(DatabaseBackup $backup, bool $deep = false, ?callable $tick = null): array
    {
        $checks = [];
        $add = function (string $key, string $label, string $status, ?string $detail = null) use (&$checks) {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
        };

        $available = $backup->isAvailable();
        $add('status', 'Backup is complete and has not been deleted', $available ? 'pass' : 'fail',
            $available ? null : 'Only a completed backup that is still on the server can be restored.');
        if (! $available) {
            return $checks;
        }

        $path = $this->backups->path($backup);
        $add('file', 'Backup file is present and readable', $path && is_readable($path) ? 'pass' : 'fail', $path ? null : 'The file is missing from the backup directory.');
        if (! $path || ! is_readable($path)) {
            return $checks;
        }
        $sizeOk = filesize($path) === (int) $backup->size_bytes;
        $add('size', 'File size matches the catalogue', $sizeOk ? 'pass' : 'fail', $sizeOk ? null : 'The file has changed size since it was made.');
        $shaOk = $backup->checksum_sha256 && hash_equals($backup->checksum_sha256, (string) hash_file('sha256', $path));
        $add('checksum', 'SHA-256 checksum matches', $shaOk ? 'pass' : 'fail', $shaOk ? null : 'The file has been altered or damaged since it was made.');

        if ($backup->encrypted) {
            $hasKey = $this->keys->find((string) $backup->key_id) !== null;
            $add('key', 'Encryption key is available', $hasKey ? 'pass' : 'fail',
                $hasKey ? null : 'It was encrypted with a key this server does not have (BACKUP_ENCRYPTION_KEY or APP_KEY at the time).');
        } else {
            $add('key', 'Backup is not encrypted', 'warn', 'Made before encryption was enabled, or with BACKUP_ENCRYPT=false.');
        }

        if ($deep && $shaOk) {
            try {
                BackupReader::verify($path, null, $this->keys, $tick);
                $add('integrity', 'Backup decrypts, decompresses and is complete', 'pass');
            } catch (BackupIntegrityException $e) {
                $add('integrity', 'Backup decrypts, decompresses and is complete', 'fail', $e->getMessage());
            }
        }

        $m = $backup->manifest ?? [];
        $engineOk = ($m['driver'] ?? 'mysql') === 'mysql' && in_array($this->mysql->connection()['driver'] ?? null, ['mysql', 'mariadb'], true);
        $add('engine', 'Database engine matches (MySQL)', $engineOk ? 'pass' : 'fail');

        $current = $this->mysql->serverVersion();
        $from = $m['server_version'] ?? null;
        [$fromMajor, $fromMinor] = $this->version($from);
        [$curMajor, $curMinor] = $this->version($current);
        $versionStatus = $from === null ? 'warn' : ($fromMajor > $curMajor ? 'fail' : ([$fromMajor, $fromMinor] === [$curMajor, $curMinor] ? 'pass' : 'warn'));
        $add('server', 'Database server version is compatible', $versionStatus, $from === null
            ? "Unknown source server; this server runs $current."
            : ($versionStatus === 'pass' ? "Made on $from; this server runs $current." : "Made on $from; this server runs $current.".($versionStatus === 'fail' ? ' Restoring onto an older major version is not supported.' : '')));

        if (($m['database'] ?? null) && $m['database'] !== $this->mysql->database()) {
            $add('database', 'Source database', 'warn', "Made from database \"{$m['database']}\"; it will be restored into \"{$this->mysql->database()}\".");
        }

        $code = $this->backups->codeMigrations();
        $taken = $m['migrations'] ?? null;
        if (! is_array($taken) || ! $taken) {
            $add('schema', 'Database schema is compatible', 'warn', 'The schema version of this backup is not recorded (imported backup). Newer migrations are applied after the restore.');
        } elseif ($unknown = array_values(array_diff($taken, $code))) {
            $add('schema', 'Database schema is compatible', 'fail', 'The backup comes from a newer version of the portal ('.count($unknown).' migration(s) this version does not have, e.g. '.$unknown[0].'). Deploy that version first.');
        } else {
            $pending = count(array_diff($code, $taken));
            $add('schema', 'Database schema is compatible', 'pass', $pending ? "$pending newer migration(s) will be applied after the restore." : 'Same schema version as this portal.');
        }

        $client = $this->mysql->available('mysql');
        $add('client', 'MySQL client is installed on the server', $client ? 'pass' : 'fail', $client ? $this->mysql->clientVersion('mysql') : 'The mysql command-line client was not found (BACKUP_MYSQL).');

        $free = @disk_free_space($this->backups->directory());
        $needed = (int) max(64 << 20, 2 * (int) DatabaseBackup::where('status', DatabaseBackup::COMPLETED)->max('size_bytes'));
        $add('space', 'Free space for the safety backup', $free === false ? 'warn' : ($free >= $needed ? 'pass' : 'fail'),
            $free === false ? 'Free space could not be measured.' : BackupService::bytes((int) $free).' free.');

        return $checks;
    }

    /** Record a restore; queued for a worker, or left for the caller to run() (the console command). */
    public function request(DatabaseBackup $backup, ?User $user, string $reason, ?string $ip, bool $queue = true): BackupRestore
    {
        $restore = $this->ops->exclusive(function () use ($backup, $user, $reason, $ip) {
            $this->ops->assertIdle();
            $checks = $this->checks($backup);
            if ($failed = collect($checks)->firstWhere('status', 'fail')) {
                throw ValidationException::withMessages(['backup' => "This backup cannot be restored: {$failed['label']} — ".($failed['detail'] ?? 'failed').'.']);
            }

            $restore = BackupRestore::create([
                'database_backup_id' => $backup->id, 'status' => BackupRestore::QUEUED, 'stage' => 'queued', 'reason' => $reason,
                'requested_by' => $user?->id, 'requested_by_name' => $user?->name ?? 'Command line (server)', 'requested_ip' => $ip,
                'checks' => $checks, 'queued_at' => now(),
            ]);
            $this->audit->record('backup.restore_requested', $backup, [], ['identifier' => $backup->identifier, 'restore' => $restore->uuid], ['reason' => $reason],
                user: $user, actorType: 'system', actorLabel: $restore->requested_by_name);

            return $restore;
        });
        if ($queue) {
            RunDatabaseRestore::dispatch($restore->id);
        }

        return $restore;
    }

    /** Run a queued restore. Claims it first; never throws (the outcome is recorded on the restore). */
    public function run(BackupRestore $restore): BackupRestore
    {
        $claimed = BackupRestore::whereKey($restore->id)->where('status', BackupRestore::QUEUED)
            ->update(['status' => BackupRestore::RUNNING, 'stage' => 'validating', 'started_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now()]);
        $restore->refresh();
        if (! $claimed) {
            return $restore;
        }
        $backup = $restore->backup()->firstOrFail();
        $requester = $restore->requested_by ? User::find($restore->requested_by) : null;
        $tick = fn () => $this->heartbeat($restore);
        $wentDown = false;
        $changed = false;

        try {
            $checks = $this->checks($backup, deep: true, tick: $tick);
            $restore->forceFill(['checks' => $checks])->save();
            if ($failed = collect($checks)->firstWhere('status', 'fail')) {
                throw new RestoreAborted("Pre-restore check failed: {$failed['label']}".($failed['detail'] ? " — {$failed['detail']}" : '').' Nothing was changed.');
            }

            $this->stage($restore, 'safety_backup');
            $safety = $this->backups->newRecord('safety', $requester, "Automatic safety backup before restoring {$backup->identifier}", restoreId: $restore->id);
            $restore->forceFill(['safety_backup_id' => $safety->id])->save();
            $safety = $this->backups->run($safety, $tick);
            if ($safety->status !== DatabaseBackup::COMPLETED) {
                throw new RestoreAborted('The safety backup of the current database failed, so nothing was changed: '.$safety->error);
            }

            $this->stage($restore, 'restoring');
            if (! app()->isDownForMaintenance()) {
                Artisan::call('down', ['--retry' => 60, '--refresh' => 30]);
                $wentDown = true;
            }
            $changed = true;
            $this->load($backup, $tick);

            $this->stage($restore, 'migrating');
            $restore->forceFill(['migrations_applied' => $this->migrate()])->save();

            $this->stage($restore, 'verifying');
            $health = $this->health($backup);
            $restore->forceFill(['health' => $health])->save();
            if ($bad = collect($health)->where('status', 'fail')->pluck('label')->all()) {
                throw new RuntimeException('Health check failed after the restore: '.implode('; ', $bad).'.');
            }
            $this->afterLoad();

            $restore->forceFill(['status' => BackupRestore::COMPLETED, 'stage' => 'done', 'completed_at' => now(), 'heartbeat_at' => now()])->save();
        } catch (Throwable $e) {
            $error = $this->backups->clean($e->getMessage());
            if ($changed) {
                $error .= ' '.$this->rollback($restore, $tick);
            }
            $restore->forceFill([
                'status' => BackupRestore::FAILED, 'error' => Str::limit($error, 4000), 'completed_at' => now(), 'heartbeat_at' => now(),
                'maintenance_left_on' => $wentDown && $restore->rollback_status === 'failed',
            ])->save();
        } finally {
            if ($wentDown && $restore->rollback_status !== 'failed') {
                Artisan::call('up');
            }
        }

        $this->report($restore->fresh(), $backup, $requester);

        return $restore->fresh();
    }

    /** Restores whose worker stopped reporting progress are recorded as interrupted. */
    public function sweepStale(): int
    {
        $count = 0;
        $stale = now()->subSeconds((int) config('maintenance.backup.stale_after', 600));
        $neverStarted = now()->subSeconds((int) config('maintenance.backup.queued_timeout', 3600));
        $stuck = BackupRestore::where(fn ($q) => $q->where('status', BackupRestore::RUNNING)->where(fn ($q) => $q->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $stale)))
            ->orWhere(fn ($q) => $q->where('status', BackupRestore::QUEUED)->where('queued_at', '<', $neverStarted))->get();

        foreach ($stuck as $restore) {
            $destructive = in_array($restore->stage, BackupRestore::DESTRUCTIVE_STAGES, true);
            $error = $restore->status === BackupRestore::QUEUED
                ? 'The restore was never started: no queue worker picked it up. Nothing was changed.'
                : "The restore stopped making progress during \"{$restore->stage}\" (the worker or server may have restarted).".($destructive
                    ? ' The database may be partly restored: the portal stays in maintenance mode until a server administrator restores the safety backup (docs/OPERATIONS.md).'
                    : ' Nothing was changed.');
            $restore->forceFill(['status' => BackupRestore::FAILED, 'error' => $error, 'completed_at' => now(), 'maintenance_left_on' => $destructive && app()->isDownForMaintenance()])->save();
            Log::log($destructive ? 'critical' : 'error', 'Database restore interrupted', ['restore' => $restore->uuid, 'stage' => $restore->stage]);
            $this->notifier->notify('backups.restore', 'Database restore interrupted', $error, '/admin/maintenance/backups', $restore->requested_by);
            $count++;
        }

        return $count;
    }

    /** Health of the database now: what a restore must pass before the portal reopens. */
    public function health(?DatabaseBackup $backup = null): array
    {
        $out = [];
        $add = function (string $label, string $status, ?string $detail = null) use (&$out) {
            $out[] = ['label' => $label, 'status' => $status, 'detail' => $detail];
        };
        try {
            DB::select('select 1');
            $add('Database connection', 'pass');
        } catch (Throwable $e) {
            $add('Database connection', 'fail', $this->backups->clean($e->getMessage()));

            return $out;
        }

        $present = collect($this->mysql->tables())->pluck('name')->all();
        $required = array_unique(array_merge(['migrations', 'users', 'roles', 'permissions', 'settings', 'advertisements', 'applications', 'audit_logs'], $backup?->manifest['tables'] ?? []));
        $missing = array_values(array_diff($required, $present));
        $add('Required tables present', $missing ? 'fail' : 'pass', $missing ? 'Missing: '.implode(', ', array_slice($missing, 0, 10)) : count($present).' tables.');

        $pending = array_diff($this->backups->codeMigrations(), DB::table('migrations')->pluck('migration')->all());
        $add('Database schema up to date', $pending ? 'fail' : 'pass', $pending ? count($pending).' migration(s) not applied.' : null);

        try {
            $users = DB::table('users')->count();
            $applications = DB::table('applications')->count();
            $add('Application data readable', 'pass', "$users users, $applications applications.");
        } catch (Throwable $e) {
            $add('Application data readable', 'fail', $this->backups->clean($e->getMessage()));
        }

        $admins = DB::table('users')->join('role_user', 'users.id', '=', 'role_user.user_id')->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('roles.slug', Role::SUPER_ADMIN)->where('users.is_active', true)->count();
        $add('An active Super Administrator exists', $admins ? 'pass' : 'warn', $admins ? null : 'Nobody can sign in to administer the portal; create one with php artisan recruitment:create-admin.');

        return $out;
    }

    // ------------------------------------------------------------------ internals

    /** Drop every table and view except the operational ones, then load the backup's SQL. */
    private function load(DatabaseBackup $backup, callable $tick): void
    {
        $path = $this->backups->path($backup);
        if (! $path) {
            throw new BackupIntegrityException("The file of backup {$backup->identifier} is missing.");
        }
        $reader = new BackupReader($path, $this->keys);
        $this->mysql->load($this->statements($reader), $tick);
    }

    private function statements(BackupReader $reader): Generator
    {
        $operational = $this->backups->operationalTables();
        $prelude = "SET FOREIGN_KEY_CHECKS=0;\n";
        foreach ($this->mysql->tables() as $object) {
            if (! in_array($object['name'], $operational, true)) {
                $prelude .= 'DROP '.($object['type'] === 'view' ? 'VIEW' : 'TABLE').' IF EXISTS '.$this->mysql->quote($object['name']).";\n";
            }
        }
        yield $prelude;

        // Line by line: MariaDB's mysqldump starts with a "sandbox mode" line the MySQL client
        // rejects, and dumps made before operational tables were left out (imported backups) still
        // carry their sections. Restoring those would sign everyone out and could re-run old jobs.
        $stripSandbox = ! $this->mysql->clientIsMariaDb('mysql');
        $carry = '';
        $skip = false;
        foreach ($reader->sql() as $piece) {
            $lines = explode("\n", $carry.$piece);
            $carry = (string) array_pop($lines);
            $out = '';
            foreach ($lines as $line) {
                if (str_starts_with($line, '-- ')) {
                    if (preg_match('/^-- (?:Table structure|Dumping data) for table `((?:[^`]|``)+)`/', $line, $m)) {
                        $skip = in_array(str_replace('``', '`', $m[1]), $operational, true);
                    } elseif (preg_match('/^-- (?:Temporary (?:table|view) structure|Final view structure|Dumping (?:routines|events)|Dump completed)/', $line)) {
                        $skip = false;
                    }
                } elseif ($stripSandbox && str_starts_with($line, '/*M!999999\- enable the sandbox mode */')) {
                    continue;
                }
                if (! $skip) {
                    $out .= $line."\n";
                }
            }
            if ($out !== '') {
                yield $out;
            }
        }
        if ($carry !== '' && ! $skip) {
            yield $carry;
        }
        $reader->close();
        yield "\nSET FOREIGN_KEY_CHECKS=1;\n";
    }

    /** @return list<string> migrations applied */
    private function migrate(): array
    {
        $pending = array_values(array_diff($this->backups->codeMigrations(), DB::table('migrations')->pluck('migration')->all()));
        if ($pending) {
            $code = Artisan::call('migrate', ['--force' => true]);
            if ($code !== 0) {
                throw new RuntimeException('Applying newer migrations after the restore failed: '.Str::limit(trim(Artisan::output()), 500));
            }
        }

        return $pending;
    }

    private function afterLoad(): void
    {
        Cache::forget('settings.all');
    }

    private function rollback(BackupRestore $restore, callable $tick): string
    {
        $safety = $restore->safety_backup_id ? DatabaseBackup::find($restore->safety_backup_id) : null;
        if (! $safety || ! $safety->isAvailable()) {
            $restore->forceFill(['rollback_status' => 'failed'])->save();
            Log::critical('Database restore failed and no safety backup is available; the portal stays in maintenance mode', ['restore' => $restore->uuid]);

            return 'No safety backup was available to roll back to; the portal stays in maintenance mode.';
        }
        $this->stage($restore, 'rolling_back');
        try {
            $this->load($safety, $tick);
            $this->migrate();
            $health = $this->health($safety);
            if ($bad = collect($health)->where('status', 'fail')->pluck('label')->all()) {
                throw new RuntimeException('health check failed: '.implode('; ', $bad));
            }
            $this->afterLoad();
            $restore->forceFill(['rollback_status' => 'succeeded'])->save();

            return "The database was rolled back to the safety backup {$safety->identifier} taken just before; nothing was lost.";
        } catch (Throwable $e) {
            $restore->forceFill(['rollback_status' => 'failed'])->save();
            Log::critical('Database restore and its rollback both failed; the portal stays in maintenance mode', ['restore' => $restore->uuid, 'safety' => $safety->identifier, 'error' => $this->backups->clean($e->getMessage())]);

            return "Rolling back to the safety backup {$safety->identifier} also failed (".$this->backups->clean($e->getMessage()).'). The portal stays in maintenance mode: a server administrator must restore it (docs/OPERATIONS.md).';
        }
    }

    private function report(BackupRestore $restore, DatabaseBackup $backup, ?User $requester): void
    {
        $requester = $requester ? User::find($requester->id) : null;  // the users table may have been replaced
        $ok = $restore->status === BackupRestore::COMPLETED;
        $meta = ['restore' => $restore->uuid, 'reason' => $restore->reason, 'requested_by' => $restore->requested_by_name, 'requested_ip' => $restore->requested_ip,
            'safety_backup' => $restore->safetyBackup?->identifier, 'migrations_applied' => count($restore->migrations_applied ?? [])];
        $this->audit->record($ok ? 'backup.restored' : 'backup.restore_failed', $backup, [], ['identifier' => $backup->identifier, 'status' => $restore->status],
            array_filter($meta + ['error' => $restore->error, 'rollback' => $restore->rollback_status]), user: $requester, actorType: 'system', actorLabel: $restore->requested_by_name);

        if ($ok) {
            Log::warning('Database restored from a backup', ['restore' => $restore->uuid, 'backup' => $backup->identifier, 'by' => $restore->requested_by_name]);
            $this->notifier->notify('backups.restore', 'Database restored', "The database was restored from backup {$backup->identifier} (requested by {$restore->requested_by_name}).", '/admin/maintenance/backups', $requester?->id);
        } else {
            Log::error('Database restore failed', ['restore' => $restore->uuid, 'backup' => $backup->identifier, 'error' => $restore->error]);
            $this->notifier->notify('backups.restore', 'Database restore failed', "Restoring backup {$backup->identifier} failed: ".Str::limit((string) $restore->error, 200), '/admin/maintenance/backups', $requester?->id);
        }
    }

    private function stage(BackupRestore $restore, string $stage): void
    {
        $restore->forceFill(['stage' => $stage, 'heartbeat_at' => now()])->save();
    }

    private function heartbeat(BackupRestore $restore): void
    {
        if ($this->lastTick !== null && time() - $this->lastTick < 5) {
            return;
        }
        $this->lastTick = time();
        BackupRestore::whereKey($restore->id)->update(['heartbeat_at' => now()]);
    }

    /** @return array{0: int, 1: int} */
    private function version(?string $version): array
    {
        return preg_match('/^(\d+)\.(\d+)/', (string) $version, $m) ? [(int) $m[1], (int) $m[2]] : [0, 0];
    }
}
