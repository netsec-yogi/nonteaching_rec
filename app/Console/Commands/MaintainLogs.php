<?php

namespace App\Console\Commands;

use App\Services\Maintenance\LogFiles;
use Illuminate\Console\Command;

/**
 * Daily log housekeeping (System maintenance → Application logs → Retention): archive inactive
 * log files older than the set age, delete archives past retention, warn when storage is high.
 */
class MaintainLogs extends Command
{
    protected $signature = 'logs:maintain';

    protected $description = 'Archive old application logs and apply log retention';

    public function handle(LogFiles $logs): int
    {
        $result = $logs->maintain();
        foreach ($result['archived'] as $name) {
            $this->line("Archived $name");
        }
        foreach ($result['deleted'] as $name) {
            $this->line("Deleted archive $name");
        }
        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}
