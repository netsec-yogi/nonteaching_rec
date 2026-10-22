<?php

namespace App\Console\Commands;

use App\Models\Advertisement;
use App\Models\User;
use App\Services\RetentionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Irreversibly removes stored files and personal data for one advertisement whose retention
 * period has ended. Requires the advertisement number to be typed twice and the name of the
 * person who authorised it. Take a backup first.
 */
class DisposeAdvertisement extends Command
{
    protected $signature = 'recruitment:dispose
        {number : Advertisement number, e.g. IIITM/NT/2026/03}
        {--confirm= : Type the advertisement number again}
        {--authorised-by= : Name and designation of the officer who authorised disposal}
        {--operator= : Email of the staff user carrying it out (recorded in the audit log)}';

    protected $description = 'Dispose of the personal data and files of an advertisement whose retention period has ended (irreversible)';

    public function handle(RetentionService $retention): int
    {
        $number = $this->argument('number');
        $ad = Advertisement::where('number', $number)->first();
        if (! $ad) {
            $this->error("No advertisement numbered {$number}.");

            return self::FAILURE;
        }
        if ($this->option('confirm') !== $number) {
            $this->error('Confirmation does not match. Re-run with --confirm="'.$number.'" to proceed.');

            return self::FAILURE;
        }
        if (blank($this->option('authorised-by'))) {
            $this->error('State who authorised the disposal with --authorised-by="Name, Designation".');

            return self::FAILURE;
        }
        $operator = $this->option('operator') ? User::where('email', $this->option('operator'))->first() : null;

        $this->warn("Disposing of {$ad->number} — {$ad->title}. This cannot be undone.");
        try {
            $counts = $retention->dispose($ad, $this->option('authorised-by'), $operator);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        foreach ($counts as $what => $n) {
            $this->line(sprintf('  %-16s %d', str_replace('_', ' ', $what), $n));
        }
        $this->info('Done. Recorded in the audit log and storage/logs/retention.log.');

        return self::SUCCESS;
    }
}
