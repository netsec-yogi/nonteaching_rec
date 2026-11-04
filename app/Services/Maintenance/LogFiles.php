<?php

namespace App\Services\Maintenance;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The application's log files (storage/logs/*.log) and their archives (storage/logs/archive/*.log.gz).
 * Files are addressed by name only: a name must match a strict pattern and resolve to a regular file
 * directly inside the log directory (no paths, no symlinks), so nothing outside it can be read or
 * removed. Files a log channel is writing to are "active" and cannot be archived or deleted.
 */
class LogFiles
{
    public const LOG_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.log$/';

    public const ARCHIVE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,180}\.log\.gz$/';

    public function __construct(
        private LogReader $reader,
        private AuditLogger $audit,
        private MaintenanceSettings $settings,
        private MaintenanceNotifier $notifier,
    ) {}

    public function directory(): string
    {
        $dir = realpath((string) config('maintenance.logs.path'));
        if ($dir === false || ! is_dir($dir)) {
            throw new RuntimeException('The log directory does not exist.');
        }

        return $dir;
    }

    public function archiveDirectory(bool $create = false): string
    {
        $dir = $this->directory().'/'.config('maintenance.logs.archive_dir', 'archive');
        if ($create) {
            File::ensureDirectoryExists($dir, 0750);
        }

        return $dir;
    }

    /** @return array{files: list<array>, archives: list<array>} */
    public function list(): array
    {
        $active = $this->activeNames();
        $files = [];
        foreach ($this->names($this->directory(), self::LOG_NAME) as $name => $path) {
            $mtime = (int) filemtime($path);
            $reason = $active[$name] ?? ($mtime >= time() - 60 * (int) config('maintenance.logs.active_minutes', 60) ? 'Written to in the last '.config('maintenance.logs.active_minutes', 60).' minutes' : null);
            $files[] = ['name' => $name, 'kind' => 'log', 'size' => (int) filesize($path), 'modified_at' => date(DATE_ATOM, $mtime),
                'date' => $this->dateOf($name, $mtime), 'active' => $reason !== null, 'active_reason' => $reason];
        }
        $archives = [];
        $archiveDir = $this->archiveDirectory();
        foreach (is_dir($archiveDir) ? $this->names($archiveDir, self::ARCHIVE_NAME) : [] as $name => $path) {
            $mtime = (int) filemtime($path);
            $archives[] = ['name' => $name, 'kind' => 'archive', 'size' => (int) filesize($path), 'modified_at' => date(DATE_ATOM, $mtime), 'date' => $this->dateOf($name, $mtime), 'active' => false, 'active_reason' => null];
        }
        $sort = fn ($a, $b) => strcmp($b['modified_at'], $a['modified_at']);
        usort($files, $sort);
        usort($archives, $sort);

        return ['files' => $files, 'archives' => $archives];
    }

    /** Absolute path of a log file or archive named by the client. 422 for a bad name, 404 if absent. */
    public function resolve(string $kind, string $name): string
    {
        $pattern = $kind === 'archive' ? self::ARCHIVE_NAME : self::LOG_NAME;
        if (! in_array($kind, ['log', 'archive'], true) || ! preg_match($pattern, $name) || str_contains($name, '..')) {
            throw ValidationException::withMessages(['file' => 'Not a valid log file name.']);
        }
        $dir = $kind === 'archive' ? $this->archiveDirectory() : $this->directory();
        $path = $dir.'/'.$name;
        abort_unless(is_file($path) && ! is_link($path) && realpath($path) !== false && dirname((string) realpath($path)) === (realpath($dir) ?: $dir), 404, 'Log file not found.');

        return $path;
    }

    /** @return array{0: bool, 1: ?string} whether a log file is active, and why */
    public function activity(string $name): array
    {
        $active = $this->activeNames();
        if (isset($active[$name])) {
            return [true, $active[$name]];
        }
        $path = $this->directory().'/'.$name;
        clearstatcache(true, $path);
        $minutes = (int) config('maintenance.logs.active_minutes', 60);
        if (is_file($path) && filemtime($path) >= time() - 60 * $minutes) {
            return [true, "Written to in the last $minutes minutes"];
        }

        return [false, null];
    }

    /** Compress a log file into the archive directory (verified), then remove the original. */
    public function archive(string $name, ?User $user, bool $automatic = false): string
    {
        $path = $this->resolve('log', $name);
        [$active, $reason] = $this->activity($name);
        if ($active) {
            throw ValidationException::withMessages(['file' => "$name is in use ($reason) and cannot be archived."]);
        }
        $mtime = (int) filemtime($path);
        $size = (int) filesize($path);
        $dir = $this->archiveDirectory(true);
        $target = $dir.'/'.$name.'.gz';
        for ($n = 1; file_exists($target); $n++) {
            $target = $dir.'/'.substr($name, 0, -4).'-'.$n.'.log.gz';
        }
        $part = $target.'.part';

        try {
            $in = fopen($path, 'rb');
            $out = gzopen($part, 'wb6');
            if (! $in || ! $out) {
                throw new RuntimeException('The archive could not be written (check permissions on storage/logs).');
            }
            while (! feof($in)) {
                $chunk = fread($in, 1048576);
                if ($chunk === false || ($chunk !== '' && gzwrite($out, $chunk) !== strlen($chunk))) {
                    throw new RuntimeException('Writing the archive failed (is the disk full?).');
                }
            }
            fclose($in);
            gzclose($out);

            // Read it back before removing anything.
            $check = gzopen($part, 'rb');
            $read = 0;
            while (! gzeof($check)) {
                $read += strlen((string) gzread($check, 1048576));
            }
            gzclose($check);
            clearstatcache(true, $path);
            if ($read !== $size || (int) filemtime($path) !== $mtime) {
                throw new RuntimeException("$name changed while it was being archived; it was left as it is.");
            }
            rename($part, $target);
            @chmod($target, 0640);
            touch($target, $mtime);
            if (! @unlink($path)) {
                @unlink($target);
                throw new RuntimeException("$name could not be removed after archiving (check file permissions).");
            }
        } catch (Throwable $e) {
            @unlink($part);
            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }

        $this->audit->record($automatic ? 'logs.auto_archived' : 'logs.archived', null, [], ['file' => $name, 'archive' => basename($target), 'size' => $size],
            user: $user, actorType: 'system', actorLabel: $automatic ? 'Log retention' : null);

        return basename($target);
    }

    public function delete(string $kind, string $name, ?User $user, bool $automatic = false): void
    {
        $path = $this->resolve($kind, $name);
        if ($kind === 'log') {
            [$active, $reason] = $this->activity($name);
            if ($active) {
                throw ValidationException::withMessages(['file' => "$name is in use ($reason) and cannot be deleted."]);
            }
        }
        $size = (int) filesize($path);
        if (! @unlink($path)) {
            throw new RuntimeException("$name could not be deleted (check file permissions).");
        }
        $this->audit->record($automatic ? 'logs.pruned' : 'logs.deleted', null, [], ['file' => $name, 'kind' => $kind, 'size' => $size],
            user: $user, actorType: 'system', actorLabel: $automatic ? 'Log retention' : null);
    }

    /**
     * Pack the named files into a temporary ZIP (the caller streams and deletes it).
     *
     * @param  list<array{kind: string, name: string}>  $files
     */
    public function zip(array $files): string
    {
        $paths = [];
        $total = 0;
        foreach ($files as $f) {
            $path = $this->resolve($f['kind'], $f['name']);
            $total += (int) filesize($path);
            $paths[($f['kind'] === 'archive' ? 'archive/' : '').$f['name']] = $path;
        }
        $max = (int) config('maintenance.logs.zip_max_bytes');
        if ($total > $max) {
            throw ValidationException::withMessages(['files' => 'The selected files total '.BackupService::bytes($total).', more than the '.BackupService::bytes($max).' a ZIP download may hold. Select fewer files.']);
        }
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZIP downloads need the PHP zip extension.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ntrec-logs');
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The ZIP file could not be created.');
        }
        foreach ($paths as $entry => $path) {
            $zip->addFile($path, $entry);
        }
        $zip->close();

        return $tmp;
    }

    public function storage(): array
    {
        $list = $this->list();
        $all = array_merge($list['files'], $list['archives']);
        $total = array_sum(array_column($all, 'size'));
        usort($all, fn ($a, $b) => $b['size'] <=> $a['size']);
        $latest = collect($list['files'])->sortByDesc('modified_at')->first();
        $dir = $this->directory();
        $warnAt = $this->settings->logs()['warn_at_mb'] * 1048576;

        return [
            'total_bytes' => $total,
            'log_bytes' => array_sum(array_column($list['files'], 'size')),
            'archive_bytes' => array_sum(array_column($list['archives'], 'size')),
            'file_count' => count($list['files']),
            'archive_count' => count($list['archives']),
            'largest' => array_slice(array_map(fn ($f) => ['name' => $f['name'], 'kind' => $f['kind'], 'size' => $f['size']], $all), 0, 5),
            'latest_modified' => $latest ? ['name' => $latest['name'], 'at' => $latest['modified_at']] : null,
            'disk_free_bytes' => (int) (@disk_free_space($dir) ?: 0) ?: null,
            'disk_total_bytes' => (int) (@disk_total_space($dir) ?: 0) ?: null,
            'warn_at_bytes' => $warnAt,
            'over_threshold' => $total >= $warnAt,
        ];
    }

    /**
     * Today's error counts across files written today, newest entry, and the categories of
     * today's errors. Cached briefly per file version, so the dashboard does not rescan.
     */
    public function today(): array
    {
        $since = now()->startOfDay()->format('Y-m-d H:i:s');
        $levels = array_fill_keys(LogReader::LEVELS, 0);
        $categories = [];
        $latest = null;
        $complete = true;
        foreach ($this->list()['files'] as $file) {
            if ($file['modified_at'] < now()->startOfDay()->toAtomString()) {
                continue;
            }
            $path = $this->directory().'/'.$file['name'];
            $key = 'logs.summary:'.md5($file['name'].'|'.$file['size'].'|'.$file['modified_at'].'|'.$since);
            $s = Cache::remember($key, 60, fn () => $this->reader->summary($path, $since, (int) config('maintenance.logs.stats_scan_bytes')));
            foreach ($s['levels'] as $l => $n) {
                $levels[$l] += $n;
            }
            foreach ($s['categories'] as $c => $n) {
                $categories[$c] = ($categories[$c] ?? 0) + $n;
            }
            if ($s['latest'] && (! $latest || $s['latest'] > $latest['at'])) {
                $latest = ['name' => $file['name'], 'at' => $s['latest']];
            }
            $complete = $complete && $s['complete'];
        }
        arsort($categories);

        return [
            'levels' => $levels,
            'errors' => $levels['error'],
            'critical' => array_sum(array_intersect_key($levels, array_flip(LogReader::SEVERE))),
            'categories' => $categories,
            'latest_entry' => $latest,
            'complete' => $complete,
        ];
    }

    /**
     * Scheduled housekeeping: archive inactive logs older than the set age, delete archives past
     * retention, and warn once a day when log storage passes the threshold.
     */
    public function maintain(): array
    {
        $config = $this->settings->logs();
        $archived = $deleted = $errors = [];

        if ($config['auto_archive']) {
            $cutoff = now()->subDays($config['archive_after_days'])->getTimestamp();
            foreach ($this->list()['files'] as $file) {
                if ($file['active'] || strtotime($file['modified_at']) >= $cutoff) {
                    continue;
                }
                try {
                    $archived[] = $this->archive($file['name'], null, automatic: true);
                } catch (Throwable $e) {
                    $errors[] = "{$file['name']}: ".$e->getMessage();
                }
            }
        }
        if ($config['retention_days'] > 0) {
            $cutoff = now()->subDays($config['retention_days'])->getTimestamp();
            foreach ($this->list()['archives'] as $file) {
                if (strtotime($file['modified_at']) < $cutoff) {
                    try {
                        $this->delete('archive', $file['name'], null, automatic: true);
                        $deleted[] = $file['name'];
                    } catch (Throwable $e) {
                        $errors[] = "{$file['name']}: ".$e->getMessage();
                    }
                }
            }
        }
        foreach ($errors as $error) {
            Log::warning('Log retention: '.$error);
        }

        $storage = $this->storage();
        $warned = false;
        if ($storage['over_threshold'] && Cache::add('logs.storage-warning:'.now()->toDateString(), true, now()->endOfDay())) {
            $message = 'Application logs use '.BackupService::bytes($storage['total_bytes']).', above the '.BackupService::bytes($storage['warn_at_bytes']).' threshold.';
            Log::warning($message);
            $this->notifier->notify('logs.manage', 'Log storage is near its limit', $message.' Archive or delete old log files.', '/admin/maintenance/logs');
            $warned = true;
        }

        return ['archived' => $archived, 'deleted' => $deleted, 'errors' => $errors, 'warned' => $warned];
    }

    /**
     * Files the configured log channels write to now (today's daily file, the single file).
     *
     * @return array<string, string> name => reason
     */
    public function activeNames(): array
    {
        $dir = $this->directory();
        $channels = (array) config('logging.channels', []);
        $out = [];
        foreach ($this->usedChannels((string) config('logging.default'), $channels) as $name) {
            $c = $channels[$name] ?? [];
            $path = $c['path'] ?? null;
            if (! is_string($path) || (realpath(dirname($path)) ?: dirname($path)) !== $dir) {
                continue;
            }
            $info = pathinfo($path);
            $ext = isset($info['extension']) ? '.'.$info['extension'] : '';
            match ($c['driver'] ?? 'single') {
                'daily' => $out[$info['filename'].'-'.now()->format('Y-m-d').$ext] = "Today's file of the \"$name\" log channel",
                'monthly' => $out[$info['filename'].'-'.now()->format('Y-m').$ext] = "This month's file of the \"$name\" log channel",
                default => $out[basename($path)] = "Written by the \"$name\" log channel",
            };
        }

        return $out;
    }

    /** @return list<string> */
    private function usedChannels(string $channel, array $channels, int $depth = 0): array
    {
        $c = $channels[$channel] ?? null;
        if (! $c || $depth > 3) {
            return [];
        }
        if (($c['driver'] ?? null) === 'stack') {
            return array_merge(...array_map(fn ($n) => $this->usedChannels(trim($n), $channels, $depth + 1), (array) ($c['channels'] ?? [])) ?: [[]]);
        }

        return [$channel];
    }

    /** @return array<string, string> name => path of regular files matching the pattern */
    private function names(string $dir, string $pattern): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $name) {
            $path = $dir.'/'.$name;
            if (preg_match($pattern, $name) && is_file($path) && ! is_link($path)) {
                $out[$name] = $path;
            }
        }

        return $out;
    }

    private function dateOf(string $name, int $mtime): string
    {
        return preg_match('/(\d{4}-\d{2}-\d{2})\.log/', $name, $m) ? $m[1] : date('Y-m-d', $mtime);
    }
}
