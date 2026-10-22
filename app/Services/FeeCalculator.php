<?php

namespace App\Services;

use App\Models\Application;

/**
 * Fee due from the post's (or advertisement's) fee schedule:
 *   {"default": 500, "exemptions": {"SC": 0, "ST": 0, "PWD": 0, "FEMALE": 0, "ESM": 0}}
 * Each matching exemption key gives an amount; the lowest applicable amount wins.
 */
class FeeCalculator
{
    public function amountDue(Application $application): float
    {
        return $this->breakdown($application)['amount'];
    }

    /** @return array{amount: float, basis: ?string} */
    public function breakdown(Application $application): array
    {
        $application->loadMissing(['personal', 'post.advertisement']);
        $schedule = $application->post->effectiveFeeSchedule();
        $amount = (float) ($schedule['default'] ?? 0);
        $basis = null;
        $p = $application->personal;

        $keys = array_filter([
            $p?->category ? strtoupper($p->category) : null,
            $p?->is_pwd ? 'PWD' : null,
            $p?->gender === 'female' ? 'FEMALE' : null,
            $p?->gender === 'transgender' ? 'TRANSGENDER' : null,
            $p?->is_ex_serviceman ? 'ESM' : null,
        ]);

        foreach ($schedule['exemptions'] ?? [] as $key => $value) {
            if (in_array(strtoupper($key), $keys, true) && (float) $value < $amount) {
                $amount = (float) $value;
                $basis = strtoupper($key);
            }
        }

        return ['amount' => $amount, 'basis' => $basis];
    }
}
