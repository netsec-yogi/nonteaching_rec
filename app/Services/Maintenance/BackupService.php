<?php

namespace App\Services\Maintenance;

use App\Jobs\RunDatabaseBackup;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Database backups: request (one operation at a time), run (mysqldump → gzip → encrypt → verify),
 * catalogue, download, delete and retention. Backups live in BACKUP_PATH, outside public/, mode
 * 0600; responses carry the file name only, never the path.
 */
class BackupService
{
    private ?int $lastTick = null;

    public function __construct(
        private MySqlTools $mysql,
        private BackupKeys $keys,
        private AuditLogger $audit,
        private MaintenanceNotifier $notifier,
        private MaintenanceOperations $ops,
        private MaintenanceSettings $settings,
    ) {}

    // ------------------------------------------------------------------ requesting and running

    /** Record a backup to run; refuses while another backup or a restore is in progress. */
    public function request(string $type, ?User $user = null, ?string $note = null, ?CarbonInterface $slot = null): DatabaseBackup
    {
        return $this->ops->exclusive(function () use ($type, $user, $note, $slot) {
            $this->ops->assertIdle();

            return $this->newRecord($type, $user, $note, $slot);
        });
    }

    /** A queued record without the idle check (the safety backup of a restore runs inside it). */
    public function newRecord(string $type, ?User $user = null, ?string $note = null, ?CarbonInterface $slot = null, ?int $restoreId = null): DatabaseBackup
    {
        $backup = DatabaseBackup::create([
            'identifier' => 'BK-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4)),
            'type' => $type,
            'status' => DatabaseBackup::QUEUED,
            'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            'schedule_slot' => $slot,
            'initiated_by' => $user?->id,
            'initiated_by_name' => $user?->name ?? match ($type) {
                'scheduled' => 'Scheduler', 'command' => 'Command line', 'imported' => 'Imported', default => 'System',
            },
            'restore_id' => $restoreId,
            'queued_at' => now(),
        ]);

        if ($type !== 'safety') {
            $this->audit->record('backup.requested', $backup, [], ['identifier' => $backup->identifier, 'type' => $type], array_filter(['note' => $note]),
                user: $user, actorType: 'system', actorLabel: $user ? null : $backup->initiated_by_name);
        }

        return $backup;
    }

    public function dispatch(DatabaseBackup $backup): void
    {
        RunDatabaseBackup::dispatch($backup->id);
    }

    /**
     * Run a queued backup. Claims the record first, so a job delivered twice runs once. Never
     * throws: a failure is recorded on the backup (with the partial file removed) and reported.
     */
    public function run(DatabaseBackup $backup, ?callable $alsoTick = null): DatabaseBackup
    {
        $claimed = DatabaseBackup::whereKey($backup->id)->where('status', DatabaseBackup::QUEUED)
            ->update(['status' => DatabaseBackup::RUNNING, 'started_at' => now(), 'heartbeat_at' => now(), 'updated_at' => now()]);
        $backup->refresh();
        if (! $claimed) {
            return $backup;
        }

        $writer = null;
        try {
            $encrypt = (bool) config('maintenance.backup.encrypt', true);
            [$key, $keyId] = $encrypt ? $this->keys->current() : [null, null];
            $filename = preg_replace('/[^A-Za-z0-9_-]/', '_', $this->mysql->database()).'_'.$backup->identifier.'.sql.gz'.($encrypt ? '.enc' : '');
            $path = $this->directory().'/'.$filename;
            $manifest = $this->manifest($backup, $encrypt);

            $writer = new BackupWriter($path, $key, $keyId);
            $backup->forceFill(['filename' => $filename, 'encrypted' => $encrypt, 'key_id' => $keyId])->save();
            $tick = function () use ($backup, $writer, $alsoTick) {
                $this->heartbeat($backup, ['size_bytes' => $writer->bytesWritten()]);
                $alsoTick && $alsoTick();
            };

            $result = $this->dumpInto($writer, $manifest, $tick);
            $verified = BackupReader::verify($path, $result['sha256'], $this->keys, $tick);
            if ($verified['dump_bytes'] !== $result['dump_bytes']) {
                throw new BackupIntegrityException('The backup read back differs in length from what was written.');
            }

            $backup->forceFill([
                'status' => DatabaseBackup::COMPLETED, 'size_bytes' => $result['size'], 'dump_bytes' => $result['dump_bytes'],
                'checksum_sha256' => $result['sha256'], 'manifest' => $manifest, 'completed_at' => now(), 'verified_at' => now(), 'heartbeat_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $writer?->abort();
            $error = $this->clean($e->getMessage());
            $backup->forceFill(['status' => DatabaseBackup::FAILED, 'filename' => null, 'error' => $error, 'completed_at' => now(), 'heartbeat_at' => now()])->save();
            Log::error('Database backup failed', ['backup' => $backup->identifier, 'type' => $backup->type, 'error' => $error]);
            $this->audit->record('backup.failed', $backup, [], ['identifier' => $backup->identifier, 'type' => $backup->type], ['error' => $error],
                actorType: 'system', actorLabel: $backup->initiated_by_name);
            if ($backup->type !== 'safety') {
                $this->notifier->notify('backups.manage', ($backup->type === 'scheduled' ? 'Scheduled backup failed' : 'Database backup failed'),
                    "Backup {$backup->identifier} failed: ".Str::limit($error, 180), '/admin/maintenance/backups', $backup->initiated_by);
            }

            return $backup;
        }

        $this->copyOffsite($backup, $path);
        $this->audit->record('backup.completed', $backup, [], [
            'identifier' => $backup->identifier, 'type' => $backup->type, 'filename' => $backup->filename,
            'size_bytes' => $backup->size_bytes, 'checksum_sha256' => $backup->checksum_sha256,
        ], array_filter(['copy_status' => $backup->copy_status]), actorType: 'system', actorLabel: $backup->initiated_by_name);

        if ($backup->type === 'manual') {
            $this->notifier->notify('backups.manage', 'Database backup completed',
                "Backup {$backup->identifier} is ready (".self::bytes($backup->size_bytes).').'.($backup->copy_status === 'failed' ? ' The off-site copy failed.' : ''),
                '/admin/maintenance/backups', $backup->initiated_by);
        } elseif ($backup->copy_status === 'failed') {
            $this->notifier->notify('backups.manage', 'Off-site backup copy failed', "Backup {$backup->identifier} was made, but copying it to the second location failed.", '/admin/maintenance/backups');
        }
        if ($backup->type !== 'safety') {
            $this->prune();
        }

        return $backup->fresh();
    }

    /**
     * Prelude (operational tables' structure) + mysqldump output, with the completion marker checked.
     *
     * @return array{size: int, sha256: string, dump_bytes: int}
     */
    public function dumpInto(BackupWriter $writer, array $manifest, callable $tick): array
    {
        $writer->write($this->prelude($manifest));
        $tail = '';
        $this->mysql->dump($this->operationalTables(), function (string $data) use ($writer, &$tail) {
            $writer->write($data);
            $tail = substr($tail.$data, -512);
        }, $tick);
        if (! preg_match('/-- Dump completed[^\n]*\s*$/', $tail)) {
            throw new RuntimeException('mysqldump ended without its completion marker, so the dump may be incomplete. (Do not pass --skip-comments in BACKUP_DUMP_OPTIONS.)');
        }

        return $writer->finish();
    }

    /** Re-read a stored backup completely: checksum, authentication, decompression, completion marker. */
    public function verify(DatabaseBackup $backup, ?User $user = null): array
    {
        $path = $this->path($backup);
        try {
            if (! $path) {
                throw new BackupIntegrityException('The backup file is no longer available.');
            }
            BackupReader::verify($path, $backup->checksum_sha256, $this->keys);
            $backup->forceFill(['verified_at' => now()])->save();
            $result = ['valid' => true, 'message' => 'The backup is complete and unaltered.'];
        } catch (BackupIntegrityException $e) {
            $result = ['valid' => false, 'message' => $e->getMessage()];
        }
        $this->audit->record('backup.verified', $backup, [], ['identifier' => $backup->identifier, 'valid' => $result['valid']], ['result' => $result['message']], user: $user);

        return $result;
    }

    // ------------------------------------------------------------------ files

    /** BACKUP_PATH, created 0700; refused if it lies inside the web root. */
    public function directory(): string
    {
        $dir = rtrim((string) config('maintenance.backup.path'), '/');
        if ($dir === '') {
            throw new RuntimeException('BACKUP_PATH is not set.');
        }
        File::ensureDirectoryExists($dir, 0700);
        $real = realpath($dir);
        $public = realpath(public_path());
        if ($real === false) {
            throw new RuntimeException('The backup directory could not be created.');
        }
        if ($public !== false && ($real === $public || str_starts_with($real.'/', $public.'/'))) {
            throw new RuntimeException('BACKUP_PATH must not be inside the public web directory.');
        }

        return $real;
    }

    /** The file of an available backup, or null. Only names this service wrote are accepted. */
    public function path(DatabaseBackup $backup): ?string
    {
        if (! $backup->filename || $backup->deleted_at || ! preg_match('/^[A-Za-z0-9_.-]+\.sql\.gz(\.enc)?$/', $backup->filename)) {
            return null;
        }
        $path = $this->directory().'/'.$backup->filename;

        return is_file($path) && ! is_link($path) ? $path : null;
    }

    public function download(DatabaseBackup $backup, User $user): BinaryFileResponse
    {
        $path = $backup->isAvailable() ? $this->path($backup) : null;
        abort_unless($path, 404, 'This backup is not available for download.');
        $this->audit->record('backup.downloaded', $backup, [], ['identifier' => $backup->identifier, 'filename' => $backup->filename], user: $user);

        return response()->download($path, $backup->filename, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** Delete a backup's file (the record stays as history). A queued backup is cancelled instead. */
    public function delete(DatabaseBackup $backup, ?User $user, ?string $reason = null, bool $retention = false): void
    {
        $this->ops->exclusive(function () use ($backup, $user, $reason, $retention) {
            $backup->refresh();
            if ($backup->deleted_at) {
                return;
            }
            if ($backup->status === DatabaseBackup::RUNNING) {
                throw new OperationInProgress('This backup is still being created and cannot be deleted yet.');
            }
            if ($this->ops->restoreUsing($backup)) {
                throw new OperationInProgress('This backup is being used by a restore in progress and cannot be deleted.');
            }

            if ($backup->status === DatabaseBackup::QUEUED) {
                $backup->forceFill(['status' => DatabaseBackup::FAILED, 'error' => 'Cancelled before it started by '.($user?->name ?? 'the system').'.', 'completed_at' => now()])->save();
                $this->audit->record('backup.cancelled', $backup, [], ['identifier' => $backup->identifier], user: $user);

                return;
            }

            if ($path = $this->path($backup)) {
                if (! @unlink($path)) {
                    throw new RuntimeException('The backup file could not be deleted (check file permissions).');
                }
            }
            if (config('maintenance.backup.copy_prune') && $backup->copy_status === 'copied' && ($disk = config('maintenance.backup.copy_disk'))) {
                rescue(fn () => Storage::disk($disk)->delete($this->copyName($backup)), report: false);
            }

            $backup->forceFill([
                'deleted_at' => now(), 'deleted_by' => $user?->id,
                'deleted_by_name' => $user?->name ?? ($retention ? 'Retention policy' : 'System'),
                'deletion_reason' => $reason !== null ? mb_substr($reason, 0, 255) : ($retention ? 'Retention policy' : null),
            ])->save();
            $this->audit->record($retention ? 'backup.pruned' : 'backup.deleted', $backup, [], [
                'identifier' => $backup->identifier, 'filename' => $backup->filename, 'created_at' => $backup->created_at?->toIso8601String(),
            ], array_filter(['reason' => $reason]), user: $user, actorType: 'system', actorLabel: $retention ? 'Retention policy' : null);
        });
    }

    /**
     * Apply retention: delete completed backups older than the retention period or beyond the
     * maximum count. The most recent valid backup is never deleted to satisfy either rule.
     *
     * @return list<string> identifiers deleted
     */
    public function prune(): array
    {
        $config = $this->settings->backup();
        $days = $config['retention_days'];
        $max = $config['max_backups'];
        if (! $days && ! $max) {
            return [];
        }

        $available = DatabaseBackup::where('status', DatabaseBackup::COMPLETED)->whereNull('deleted_at')
            ->orderByDesc('completed_at')->orderByDesc('id')->get()->values();
        $cutoff = $days ? now()->subDays($days) : null;
        $deleted = [];
        foreach ($available as $rank => $backup) {
            if ($rank === 0) {
                continue;
            }
            $tooOld = $cutoff && $backup->completed_at?->lt($cutoff);
            $tooMany = $max && $rank >= $max;
            if (! $tooOld && ! $tooMany) {
                continue;
            }
            try {
                $this->delete($backup, null, $tooOld ? "Older than {$days} days" : "More than {$max} backups", retention: true);
                $deleted[] = $backup->identifier;
            } catch (Throwable $e) {
                Log::warning('Backup retention could not delete a backup', ['backup' => $backup->identifier, 'error' => $e->getMessage()]);
            }
        }

        return $deleted;
    }

    /**
     * Record operations that stopped reporting progress (worker killed, server restarted) as
     * failed, and queued ones that no worker ever started.
     */
    public function sweepStale(): int
    {
        $count = 0;
        $stale = now()->subSeconds((int) config('maintenance.backup.stale_after', 600));
        foreach (DatabaseBackup::where('status', DatabaseBackup::RUNNING)->where(fn ($q) => $q->whereNull('heartbeat_at')->orWhere('heartbeat_at', '<', $stale))->get() as $backup) {
            if ($backup->filename && is_file($file = $this->directory().'/'.$backup->filename)) {
                @unlink($file);
            }
            $this->failStale($backup, 'The backup stopped making progress (the worker or server may have restarted). The partial file was removed.');
            $count++;
        }
        $neverStarted = now()->subSeconds((int) config('maintenance.backup.queued_timeout', 3600));
        foreach (DatabaseBackup::where('status', DatabaseBackup::QUEUED)->where('queued_at', '<', $neverStarted)->get() as $backup) {
            $this->failStale($backup, 'The backup was never started: no queue worker picked it up. Check that the queue worker is running.');
            $count++;
        }

        return $count;
    }

    private function failStale(DatabaseBackup $backup, string $error): void
    {
        $backup->forceFill(['status' => DatabaseBackup::FAILED, 'filename' => null, 'error' => $error, 'completed_at' => now()])->save();
        Log::error('Database backup interrupted', ['backup' => $backup->identifier, 'error' => $error]);
        $this->audit->record('backup.failed', $backup, [], ['identifier' => $backup->identifier, 'type' => $backup->type], ['error' => $error], actorType: 'system', actorLabel: 'Maintenance sweep');
        $this->notifier->notify('backups.manage', 'Database backup failed', "Backup {$backup->identifier}: {$error}", '/admin/maintenance/backups', $backup->initiated_by);
    }

    // ------------------------------------------------------------------ reporting

    public function overview(BackupSchedule $schedule): array
    {
        $available = DatabaseBackup::where('status', DatabaseBackup::COMPLETED)->whereNull('deleted_at');
        $latest = (clone $available)->orderByDesc('completed_at')->first();
        $lastScheduled = DatabaseBackup::where('type', 'scheduled')->where('status', DatabaseBackup::COMPLETED)->orderByDesc('completed_at')->first();
        $dir = rescue(fn () => $this->directory(), null, false);

        return [
            'available' => (clone $available)->count(),
            'storage_bytes' => (int) (clone $available)->sum('size_bytes'),
            'latest' => $latest?->summary(),
            'last_scheduled_success_at' => $lastScheduled?->completed_at?->toIso8601String(),
            'failed_30_days' => DatabaseBackup::where('status', DatabaseBackup::FAILED)->where('created_at', '>=', now()->subDays(30))->count(),
            'failed_total' => DatabaseBackup::where('status', DatabaseBackup::FAILED)->count(),
            'last_failure' => DatabaseBackup::where('status', DatabaseBackup::FAILED)->latest('id')->first()?->summary(),
            'next_scheduled_at' => $schedule->next()?->toIso8601String(),
            'schedule' => $schedule->describe(),
            'encrypted' => (bool) config('maintenance.backup.encrypt', true),
            'offsite_copy' => (bool) config('maintenance.backup.copy_disk'),
            'disk_free_bytes' => $dir ? (int) (@disk_free_space($dir) ?: 0) : null,
            'disk_total_bytes' => $dir ? (int) (@disk_total_space($dir) ?: 0) : null,
            'recent_sizes' => DatabaseBackup::where('status', DatabaseBackup::COMPLETED)->orderByDesc('completed_at')->limit(14)->get(['identifier', 'completed_at', 'size_bytes'])
                ->reverse()->values()->map(fn ($b) => ['identifier' => $b->identifier, 'completed_at' => $b->completed_at?->toIso8601String(), 'size_bytes' => $b->size_bytes]),
        ];
    }

    /** @return list<string> */
    public function operationalTables(): array
    {
        return array_values((array) config('maintenance.backup.operational_tables', []));
    }

    /** @return list<string> migration names shipped with this version of the portal */
    public function codeMigrations(): array
    {
        return collect(File::glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'))->sort()->values()->all();
    }

    public function heartbeat(DatabaseBackup $backup, array $extra = []): void
    {
        if ($this->lastTick !== null && time() - $this->lastTick < 5) {
            return;
        }
        $this->lastTick = time();
        DatabaseBackup::whereKey($backup->id)->update(['heartbeat_at' => now(), ...$extra]);
    }

    /** Strip credentials and paths from text before it is stored or shown. */
    public function clean(string $text): string
    {
        $text = app(LogRedactor::class)->redact($text);
        $dir = (string) config('maintenance.backup.path');
        foreach (array_filter([$dir, realpath($dir) ?: null, sys_get_temp_dir()]) as $path) {
            $text = str_replace($path, '[path]', $text);
        }

        return Str::limit(trim($text), 2000);
    }

    public static function bytes(?int $bytes): string
    {
        $bytes = (int) $bytes;
        foreach (['GB' => 1 << 30, 'MB' => 1 << 20, 'KB' => 1 << 10] as $unit => $size) {
            if ($bytes >= $size) {
                return round($bytes / $size, 1).' '.$unit;
            }
        }

        return $bytes.' B';
    }

    // ------------------------------------------------------------------ internals

    private function manifest(DatabaseBackup $backup, bool $encrypted): array
    {
        $operational = $this->operationalTables();
        $objects = collect($this->mysql->tables());

        return [
            'format' => 1,
            'identifier' => $backup->identifier,
            'database' => $this->mysql->database(),
            'driver' => 'mysql',
            'server_version' => $this->mysql->serverVersion(),
            'client' => $this->mysql->clientVersion('dump'),
            'app_version' => $this->appVersion(),
            'timezone' => config('app.timezone'),
            'tables' => $objects->where('type', 'table')->pluck('name')->diff($operational)->values()->all(),
            'views' => $objects->where('type', 'view')->pluck('name')->values()->all(),
            'operational_tables' => $objects->where('type', 'table')->pluck('name')->intersect($operational)->values()->all(),
            'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
            'encrypted' => $encrypted,
            'created_at' => now()->toIso8601String(),
        ];
    }

    private function prelude(array $manifest): string
    {
        $lines = [
            '-- '.config('app.name').' database backup '.$manifest['identifier'],
            "-- Database {$manifest['database']} (server {$manifest['server_version']}), created {$manifest['created_at']}",
            '-- Operational tables (queue, cache, sessions, backup catalogue) are created if missing; their rows are not included.',
            '/*!40101 SET NAMES utf8mb4 */;',
            '/*!40014 SET @NTREC_OLD_FKC=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;',
        ];
        foreach ($manifest['operational_tables'] as $table) {
            $lines[] = $this->mysql->createStatement($table).';';
        }
        $lines[] = '/*!40014 SET FOREIGN_KEY_CHECKS=@NTREC_OLD_FKC */;';

        return implode("\n", $lines)."\n\n";
    }

    private function copyOffsite(DatabaseBackup $backup, string $path): void
    {
        $disk = config('maintenance.backup.copy_disk');
        if (! $disk) {
            return;
        }
        try {
            $stream = fopen($path, 'rb');
            $ok = Storage::disk($disk)->writeStream($this->copyName($backup), $stream);
            is_resource($stream) && fclose($stream);
            if ($ok === false || (int) Storage::disk($disk)->size($this->copyName($backup)) !== (int) $backup->size_bytes) {
                throw new RuntimeException('The copy was not written completely.');
            }
            $backup->forceFill(['copy_status' => 'copied', 'copied_at' => now(), 'copy_error' => null])->save();
        } catch (Throwable $e) {
            $backup->forceFill(['copy_status' => 'failed', 'copy_error' => Str::limit($this->clean($e->getMessage()), 480)])->save();
            Log::warning('Off-site backup copy failed', ['backup' => $backup->identifier, 'disk' => $disk, 'error' => $backup->copy_error]);
        }
    }

    private function copyName(DatabaseBackup $backup): string
    {
        return trim((string) config('maintenance.backup.copy_prefix', ''), '/').'/'.$backup->filename;
    }

    private function appVersion(): ?string
    {
        $head = base_path('.git/HEAD');
        if (! is_file($head)) {
            return null;
        }
        $ref = trim((string) @file_get_contents($head));
        if (str_starts_with($ref, 'ref: ')) {
            $file = base_path('.git/'.substr($ref, 5));
            $ref = is_file($file) ? trim((string) @file_get_contents($file)) : '';
        }

        return preg_match('/^[0-9a-f]{7,40}$/', $ref) ? substr($ref, 0, 12) : null;
    }
}
