<?php

namespace App\Services\Maintenance;

use App\Services\SettingsService;
use Illuminate\Support\Facades\Validator;

/**
 * Backup schedule, backup retention and log retention: rows in `settings` under "maintenance.",
 * edited only through the System maintenance screens (each with its own permission), never through
 * the general Settings screen.
 */
class MaintenanceSettings
{
    public const BACKUP = [
        'enabled' => true,
        'frequency' => 'daily',     // daily | weekly | monthly
        'time' => '01:30',
        'weekday' => 0,             // 0 = Sunday … 6 = Saturday (weekly)
        'month_day' => 1,           // 1–28 (monthly)
        'retention_days' => null,   // null: BACKUP_KEEP_DAYS; 0 = no age limit
        'max_backups' => 0,         // 0 = no count limit
    ];

    public const LOGS = [
        'auto_archive' => true,
        'archive_after_days' => 7,
        'retention_days' => 365,    // archives older than this are deleted; 0 = keep
        'warn_at_mb' => 1024,
    ];

    public function __construct(private SettingsService $settings) {}

    /** @return array<string, mixed> */
    public function backup(): array
    {
        $out = [];
        foreach (self::BACKUP as $key => $default) {
            $out[$key] = $this->settings->get("maintenance.backup.$key", $default);
        }
        $out['retention_days'] ??= (int) config('maintenance.backup.retention_days', 30);
        $out['since'] = $this->settings->get('maintenance.backup.schedule_since');

        return $this->castBackup($out);
    }

    /** @return array<string, mixed> */
    public function logs(): array
    {
        $out = [];
        foreach (self::LOGS as $key => $default) {
            $out[$key] = $this->settings->get("maintenance.logs.$key", $default);
        }

        return [
            'auto_archive' => (bool) $out['auto_archive'],
            'archive_after_days' => (int) $out['archive_after_days'],
            'retention_days' => (int) $out['retention_days'],
            'warn_at_mb' => (int) $out['warn_at_mb'],
        ];
    }

    /** @return array{0: array, 1: array} old and new values of what changed */
    public function updateBackup(array $input): array
    {
        $data = Validator::make($input, [
            'enabled' => ['required', 'boolean'],
            'frequency' => ['required', 'in:daily,weekly,monthly'],
            'time' => ['required', 'date_format:H:i'],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'month_day' => ['required', 'integer', 'between:1,28'],
            'retention_days' => ['required', 'integer', 'between:0,3650'],
            'max_backups' => ['required', 'integer', 'between:0,1000'],
        ], [], ['month_day' => 'day of the month', 'max_backups' => 'maximum number of backups'])->validate();

        $old = $this->backup();
        [$before, $after] = $this->store('backup', $old, $data);
        // A changed schedule only counts slots from now on (no immediate catch-up run).
        if (array_intersect(array_keys($after), ['enabled', 'frequency', 'time', 'weekday', 'month_day'])) {
            $this->settings->set('maintenance.backup.schedule_since', now()->toIso8601String());
        }

        return [$before, $after];
    }

    /** @return array{0: array, 1: array} */
    public function updateLogs(array $input): array
    {
        $data = Validator::make($input, [
            'auto_archive' => ['required', 'boolean'],
            'archive_after_days' => ['required', 'integer', 'between:1,3650'],
            'retention_days' => ['required', 'integer', 'between:0,3650'],
            'warn_at_mb' => ['required', 'integer', 'between:1,1048576'],
        ], [], ['warn_at_mb' => 'storage warning threshold'])->validate();

        return $this->store('logs', $this->logs(), $data);
    }

    private function store(string $group, array $old, array $data): array
    {
        $defaults = $group === 'backup' ? self::BACKUP : self::LOGS;
        $before = $after = [];
        foreach ($data as $key => $value) {
            $default = $defaults[$key] ?? null;
            $value = match (true) {
                is_bool($default) => (bool) $value,
                is_string($default) => (string) $value,
                default => (int) $value,
            };
            if (($old[$key] ?? null) !== $value) {
                $this->settings->set("maintenance.$group.$key", $value);
                $before[$key] = $old[$key] ?? null;
                $after[$key] = $value;
            }
        }

        return [$before, $after];
    }

    private function castBackup(array $v): array
    {
        return [
            'enabled' => (bool) $v['enabled'],
            'frequency' => in_array($v['frequency'], ['daily', 'weekly', 'monthly'], true) ? $v['frequency'] : 'daily',
            'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v['time']) ? (string) $v['time'] : '01:30',
            'weekday' => max(0, min(6, (int) $v['weekday'])),
            'month_day' => max(1, min(28, (int) $v['month_day'])),
            'retention_days' => max(0, (int) $v['retention_days']),
            'max_backups' => max(0, (int) $v['max_backups']),
            'since' => is_string($v['since']) ? $v['since'] : null,
        ];
    }
}
