<?php

namespace App\Console\Commands;

use App\Services\Maintenance\BackupService;
use App\Services\Maintenance\RestoreService;
use Illuminate\Console\Command;

/**
 * Records backups and restores whose worker stopped reporting progress (or that no worker ever
 * started) as failed, so they stop blocking new operations and administrators are told.
 * Scheduled every five minutes, also during maintenance mode.
 */
class MaintenanceSweep extends Command
{
    protected $signature = 'maintenance:sweep';

    protected $description = 'Fail backups and restores that stopped making progress';

    public function handle(BackupService $backups, RestoreService $restores): int
    {
        $count = $restores->sweepStale() + $backups->sweepStale();
        if ($count) {
            $this->warn("$count interrupted operation(s) recorded as failed.");
        }

        return self::SUCCESS;
    }
}
