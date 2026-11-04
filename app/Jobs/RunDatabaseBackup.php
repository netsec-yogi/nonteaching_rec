<?php

namespace App\Jobs;

use App\Models\DatabaseBackup;
use App\Services\Maintenance\BackupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs one queued backup in the background. BackupService::run() claims the record, so a job the
 * queue delivers twice (a long backup outliving retry_after) does nothing the second time.
 */
class RunDatabaseBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = false;

    public function __construct(public int $backupId)
    {
        $this->timeout = (int) config('maintenance.backup.timeout', 3600) + 120;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(BackupService $backups): void
    {
        if ($backup = DatabaseBackup::find($this->backupId)) {
            $backups->run($backup);
        }
    }

    /** Only a backup that never started is failed here; a running one is left to its own outcome. */
    public function failed(?Throwable $e): void
    {
        DatabaseBackup::whereKey($this->backupId)->where('status', DatabaseBackup::QUEUED)->update([
            'status' => DatabaseBackup::FAILED, 'completed_at' => now(),
            'error' => 'The backup job could not be started: '.mb_substr((string) $e?->getMessage(), 0, 500),
        ]);
    }
}
