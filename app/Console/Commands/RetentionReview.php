<?php

namespace App\Console\Commands;

use App\Services\RetentionService;
use Illuminate\Console\Command;

class RetentionReview extends Command
{
    protected $signature = 'recruitment:retention-review';

    protected $description = 'Archive advertisements whose retention period has ended and flag them for disposal (deletes nothing)';

    public function handle(RetentionService $retention): int
    {
        $due = $retention->review();
        $this->info($due->isEmpty() ? 'No advertisement has reached the end of its retention period.' : 'Flagged: '.$due->pluck('number')->implode(', '));

        return self::SUCCESS;
    }
}
