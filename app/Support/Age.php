<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Age in completed years and months by calendar arithmetic, never days ÷ 365. N months are complete
 * on the date N calendar months after the birth date; in a month too short for the birth day (the
 * 31st, or 29 February in a common year) they are complete on that month's last day. Display only:
 * no eligibility decision is drawn from it. resources/js/utils/age.ts mirrors this for live display.
 */
final class Age
{
    /** @return array{years: int, months: int}|null null when either date is missing or the birth date is later. */
    public static function between(?CarbonInterface $birth, ?CarbonInterface $on): ?array
    {
        if (! $birth || ! $on || $birth->toDateString() > $on->toDateString()) {
            return null;
        }
        $months = ($on->year - $birth->year) * 12 + $on->month - $birth->month;
        if ($on->day < min($birth->day, $on->daysInMonth)) {
            $months--;
        }

        return ['years' => intdiv($months, 12), 'months' => $months % 12];
    }

    public static function format(int $years, int $months): string
    {
        return $years.' '.($years === 1 ? 'Year' : 'Years').', '.$months.' '.($months === 1 ? 'Month' : 'Months');
    }
}
