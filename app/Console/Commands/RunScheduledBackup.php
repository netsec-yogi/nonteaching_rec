<?php

namespace App\Console\Commands;

use App\Models\DatabaseBackup;
use App\Services\Maintenance\BackupSchedule;
use App\Services\Maintenance\BackupService;
use App\Services\Maintenance\OperationInProgress;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Runs every minute from the scheduler and takes the scheduled backup when its slot is due
 * (System maintenance → Database backups → Schedule). Runs in this process, so it needs no
 * queue worker and nobody signed in. While another backup or restore runs it waits for the
 * next minute; each slot is backed up once (unique `schedule_slot`).
 */
class RunScheduledBackup extends Command
{
    protected $signature = 'backup:scheduled';

    protected $description = 'Take the scheduled database backup if one is due';

    public function handle(BackupSchedule $schedule, BackupService $backups): int
    {
        $slot = $schedule->due();
        if (! $slot) {
            return self::SUCCESS;
        }

        try {
            $backup = $backups->request('scheduled', null, null, $slot);
        } catch (OperationInProgress $e) {
            $this->line('Scheduled backup waiting: '.$e->getMessage());

            return self::SUCCESS;
        } catch (UniqueConstraintViolationException) {
            return self::SUCCESS; // another scheduler took this slot
        }

        $backup = $backups->run($backup);
        if ($backup->status !== DatabaseBackup::COMPLETED) {
            $this->error("Scheduled backup {$backup->identifier} failed: {$backup->error}");

            return self::FAILURE;
        }
        $this->info("Scheduled backup {$backup->identifier} completed (".BackupService::bytes($backup->size_bytes).').');

        return self::SUCCESS;
    }
}
