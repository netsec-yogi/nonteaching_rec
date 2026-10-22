<?php

namespace App\Console\Commands;

use App\Models\Advertisement;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

class CloseExpiredAdvertisements extends Command
{
    protected $signature = 'advertisements:close-expired';

    protected $description = 'Mark published advertisements past their closing time as closed';

    public function handle(AuditLogger $audit): int
    {
        $count = 0;
        Advertisement::where('status', Advertisement::PUBLISHED)->where('closes_at', '<', now())->each(function (Advertisement $ad) use ($audit, &$count) {
            $ad->forceFill(['status' => Advertisement::CLOSED])->save();
            $audit->record('advertisement.status_changed', $ad, ['status' => 'published'], ['status' => 'closed'], ['reason' => 'Closing date passed'], actorType: 'system', actorLabel: 'scheduler');
            $count++;
        });
        $this->info("Closed {$count} advertisement(s).");

        return self::SUCCESS;
    }
}
