<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A database counter that hands out each value exactly once, across queue workers, servers and
 * processes: the increment takes a row lock that is held until the value has been read back.
 */
final class AtomicCounter
{
    public static function next(string $name): int
    {
        return DB::transaction(function () use ($name) {
            if (! DB::table('atomic_counters')->where('name', $name)->increment('value')) {
                DB::table('atomic_counters')->insertOrIgnore(['name' => $name, 'value' => 0]);
                DB::table('atomic_counters')->where('name', $name)->increment('value');
            }

            return (int) DB::table('atomic_counters')->where('name', $name)->value('value');
        });
    }
}
