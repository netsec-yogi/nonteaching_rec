<?php

namespace App\Services\Maintenance;

use App\Models\BackupRestore;
use App\Models\DatabaseBackup;
use Illuminate\Support\Facades\Cache;

/**
 * One backup or restore at a time. The queued/running rows are the long-lived "in progress" state;
 * a short cache lock makes the check-then-create atomic across web servers, workers and the
 * scheduler. An operation that has stopped reporting progress no longer blocks (the sweeper then
 * records it as interrupted).
 */
class MaintenanceOperations
{
    public function exclusive(callable $callback): mixed
    {
        return Cache::lock('system-maintenance:operation', 60)->block(20, $callback);
    }

    public function active(): DatabaseBackup|BackupRestore|null
    {
        $stale = now()->subSeconds((int) config('maintenance.backup.stale_after', 600));
        $neverStarted = now()->subSeconds((int) config('maintenance.backup.queued_timeout', 3600));
        $live = fn ($q) => $q->where(fn ($q) => $q->where('status', 'running')->where('heartbeat_at', '>=', $stale))
            ->orWhere(fn ($q) => $q->where('status', 'queued')->where('queued_at', '>=', $neverStarted));

        return BackupRestore::where($live)->latest('id')->first()
            ?? DatabaseBackup::where($live)->latest('id')->first();
    }

    public function assertIdle(): void
    {
        $active = $this->active();
        if ($active instanceof BackupRestore) {
            throw new OperationInProgress('A database restore is in progress. Wait for it to finish.');
        }
        if ($active instanceof DatabaseBackup) {
            throw new OperationInProgress("Backup {$active->identifier} is {$active->status}. Wait for it to finish before starting another backup or a restore.");
        }
    }

    /** A restore that is queued or running and reads or writes this backup. */
    public function restoreUsing(DatabaseBackup $backup): ?BackupRestore
    {
        return BackupRestore::whereIn('status', [BackupRestore::QUEUED, BackupRestore::RUNNING])
            ->where(fn ($q) => $q->where('database_backup_id', $backup->id)->orWhere('safety_backup_id', $backup->id))->first();
    }
}
