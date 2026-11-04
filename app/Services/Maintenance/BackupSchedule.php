<?php

namespace App\Services\Maintenance;

use App\Models\DatabaseBackup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When scheduled backups are due. Each run of the schedule is a "slot" (e.g. today 01:30); the
 * backup for a slot records it in a unique column, so it runs once however many schedulers or
 * minutes see it. A slot missed while the server was down runs as soon as the scheduler is back
 * (only the latest one: three missed nights produce one backup, not three).
 */
class BackupSchedule
{
    public function __construct(private MaintenanceSettings $settings) {}

    /** The slot to run now, or null when none is due. */
    public function due(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now());
        $config = $this->settings->backup();
        if (! $config['enabled']) {
            return null;
        }
        $slot = $this->latest($now, $config);
        // Slots before the schedule was last changed are not caught up.
        if ($config['since'] && $slot->lt(CarbonImmutable::parse($config['since'])->subMinute())) {
            return null;
        }
        if (DatabaseBackup::where('schedule_slot', $slot)->exists()) {
            return null;
        }

        return $slot;
    }

    /** The next slot after `$now` (or the current one while it has not run yet); null when disabled. */
    public function next(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now());
        $config = $this->settings->backup();
        if (! $config['enabled']) {
            return null;
        }

        return $this->due($now) ?? $this->following($this->latest($now, $config), $config);
    }

    /** The most recent slot at or before `$now`. */
    public function latest(CarbonInterface $now, ?array $config = null): CarbonImmutable
    {
        $config ??= $this->settings->backup();
        $now = CarbonImmutable::instance($now);
        [$hour, $minute] = array_map('intval', explode(':', $config['time']));
        $today = $now->setTime($hour, $minute);

        return match ($config['frequency']) {
            'weekly' => ($c = $today->subDays(($today->dayOfWeek - $config['weekday'] + 7) % 7))->gt($now) ? $c->subWeek() : $c,
            'monthly' => ($c = $today->setDay($config['month_day']))->gt($now) ? $c->subMonthNoOverflow() : $c,
            default => $today->gt($now) ? $today->subDay() : $today,
        };
    }

    private function following(CarbonImmutable $slot, array $config): CarbonImmutable
    {
        return match ($config['frequency']) {
            'weekly' => $slot->addWeek(),
            'monthly' => $slot->addMonthNoOverflow(),
            default => $slot->addDay(),
        };
    }

    public function describe(): string
    {
        $c = $this->settings->backup();
        if (! $c['enabled']) {
            return 'Automatic backups are off';
        }
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return match ($c['frequency']) {
            'weekly' => "Every {$days[$c['weekday']]} at {$c['time']}",
            'monthly' => "Monthly on day {$c['month_day']} at {$c['time']}",
            default => "Daily at {$c['time']}",
        };
    }
}
