<?php

namespace App\Console\Commands;

use App\Services\RefereeService;
use Illuminate\Console\Command;

class SendRefereeReminders extends Command
{
    protected $signature = 'referees:send-reminders';

    protected $description = 'Send referee reminders that are due under each advertisement\'s schedule';

    public function handle(RefereeService $referees): int
    {
        $sent = $referees->sendDueReminders();
        $this->info("Queued {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
