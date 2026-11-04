<?php

namespace App\Jobs;

use App\Models\BackupRestore;
use App\Services\Maintenance\RestoreService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one restore in the background (checks, safety backup, load, migrations, health checks).
 * Claimed like a backup, so a duplicate delivery never restores twice.
 */
class RunDatabaseRestore implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = false;

    public function __construct(public int $restoreId)
    {
        // Safety backup, full verification and the load each get the backup time limit.
        $this->timeout = 3 * (int) config('maintenance.backup.timeout', 3600) + 300;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(RestoreService $restores): void
    {
        if ($restore = BackupRestore::find($this->restoreId)) {
            $restores->run($restore);
        }
    }

    public function failed(?Throwable $e): void
    {
        BackupRestore::whereKey($this->restoreId)->where('status', BackupRestore::QUEUED)->update([
            'status' => BackupRestore::FAILED, 'completed_at' => now(),
            'error' => 'The restore job could not be started; nothing was changed. '.mb_substr((string) $e?->getMessage(), 0, 500),
        ]);
    }
}
