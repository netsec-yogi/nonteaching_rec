<?php

namespace App\Console\Commands;

use App\Services\Maintenance\BackupService;
use Illuminate\Console\Command;

/** Applies the backup retention policy (also applied after every backup). Never removes the newest valid backup. */
class PruneBackups extends Command
{
    protected $signature = 'backup:prune';

    protected $description = 'Delete backups beyond the configured retention period or count';

    public function handle(BackupService $backups): int
    {
        $deleted = $backups->prune();
        foreach ($deleted as $identifier) {
            $this->line("Pruned $identifier");
        }
        $this->info(count($deleted).' backup(s) pruned.');

        return self::SUCCESS;
    }
}
