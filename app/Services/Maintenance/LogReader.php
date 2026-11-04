<?php

namespace App\Services\Maintenance;

use Generator;

/**
 * Reads Laravel log files from the end backwards, in fixed-size blocks: the newest entries come
 * first and no request reads more than a set number of bytes, however large the file. An entry is
 * a "[timestamp] env.LEVEL: message" line plus the lines after it (context, stack trace). Files in
 * another format are shown line by line. Text is masked by LogRedactor before it leaves the server.
 */
class LogReader
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** Levels counted as "errors" / "critical" on the dashboard. */
    public const SEVERE = ['critical', 'alert', 'emergency'];

    public const CATEGORIES = [
        'database_connection' => 'Database connection',
        'sql' => 'SQL / query errors',
        'mail' => 'Email / SMTP delivery',
        'queue' => 'Queue jobs',
        'authentication' => 'Authentication',
        'authorization' => 'Authorization',
        'filesystem' => 'Files and permissions',
        'application' => 'Other application errors',
    ];

    private const HEADER = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2})(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?\]\s+([^\s.:\]]+)\.([A-Za-z]+):\s?/';

    private const BLOCK = 262144;

    private const MAX_ENTRY_LINES_BYTES = 262144;

    private const PATTERNS = [
        'mail' => '/Symfony\\\\+Component\\\\+Mailer|TransportException|\bSMTP\b|Expected response code|Failed to authenticate on SMTP|MailDeliveryFailed|Connection could not be established with host/i',
        'database_connection' => '/SQLSTATE\[(?:HY000|08\w{3})\] \[(?:1040|1045|2002|2003|2005|2006|2013)\]|server has gone away|Lost connection to (?:MySQL )?server|Too many connections|Access denied for user|Connection refused.*(?:mysql|3306)/i',
        'sql' => '/QueryException|SQLSTATE\[|PDOException|Integrity constraint violation|Deadlock found|Lock wait timeout/i',
        'queue' => '/MaxAttemptsExceededException|TimeoutExceededException|has been attempted too many times|ManuallyFailedException|Illuminate\\\\+Queue\\\\+|\bjob\b.*\bfailed\b/i',
        'authentication' => '/AuthenticationException|Unauthenticated|TokenMismatchException|CSRF token mismatch|invalid credentials|account (?:is )?locked/i',
        'authorization' => '/AuthorizationException|This action is unauthorized|AccessDeniedHttpException|You do not have permission/i',
        'filesystem' => '/Permission denied|failed to open stream|Failed to open stream|UnableTo(?:Write|Read|Create|Delete|Move|Copy)File|UnableToCreateDirectory|No space left on device|Disk quota exceeded|is not writable/i',
    ];

    public function __construct(private LogRedactor $redactor) {}

    /**
     * Entries newest first, ending before byte offset `$before` (null: end of file).
     *
     * @param  array{levels?: list<string>, q?: ?string, exception?: ?string, category?: ?string, from?: ?string, to?: ?string}  $filters  from/to as "Y-m-d H:i:s"
     * @return array{entries: list<array>, next_cursor: ?int, scanned_bytes: int, limited: bool, structured: bool}
     */
    public function entries(string $path, array $filters, ?int $before, int $limit, int $maxScan): array
    {
        $structured = $this->structured($path);
        $end = $before === null ? (int) filesize($path) : max(0, min($before, (int) filesize($path)));
        $scan = $this->scan($path, $before, $maxScan, ! $structured);
        $entries = [];
        $next = null;
        $stopped = null;   // 'page' (limit reached) or 'older' (past the "from" time)
        $lastOffset = $end;

        foreach ($scan as $raw) {
            $lastOffset = $raw['offset'];
            if ($raw['ts'] !== null && ! empty($filters['from']) && $raw['ts'] < $filters['from']) {
                $stopped = 'older'; // everything before this is older still

                break;
            }
            $entry = $this->matching($raw, $filters);
            if ($entry !== null) {
                $entries[] = $entry;
                if (count($entries) >= $limit) {
                    $next = $raw['offset'] > 0 ? $raw['offset'] : null;
                    $stopped = 'page';

                    break;
                }
            }
        }
        $state = $stopped === null ? $scan->getReturn() : null;

        return [
            'entries' => $entries,
            // Where the next request continues (null: nothing older can match). After a full page it
            // is the start of the page's oldest entry; after the byte limit, where reading stopped.
            'next_cursor' => $stopped === 'page' ? $next : ($stopped === 'older' ? null : ($state['resume'] ?? null)),
            'scanned_bytes' => $stopped === null ? (int) ($state['scanned'] ?? 0) : max(0, $end - $lastOffset),
            'limited' => $stopped === null && ($state['limited'] ?? false),
            'structured' => $structured,
        ];
    }

    /**
     * Counts by level (and category for errors) of entries at or after `$since` ("Y-m-d H:i:s"),
     * reading back from the end until older entries are reached.
     *
     * @return array{levels: array<string, int>, categories: array<string, int>, latest: ?string, complete: bool}
     */
    public function summary(string $path, string $since, int $maxScan): array
    {
        $levels = array_fill_keys(self::LEVELS, 0);
        $categories = array_fill_keys(array_keys(self::CATEGORIES), 0);
        $latest = null;
        $complete = true;
        $scan = $this->scan($path, null, $maxScan, false, headersOnly: true);
        foreach ($scan as $raw) {
            if ($raw['ts'] === null) {
                continue;
            }
            $latest ??= $raw['ts'];
            if ($raw['ts'] < $since) {
                break;
            }
            $level = $raw['level'];
            if (isset($levels[$level])) {
                $levels[$level]++;
            }
            if (in_array($level, ['error', 'critical', 'alert', 'emergency'], true)) {
                $categories[$this->category($level, $raw['line'])]++;
            }
        }
        if ($scan->valid() === false && ($state = $scan->getReturn()) && ($state['limited'] ?? false)) {
            $complete = false;
        }

        return ['levels' => $levels, 'categories' => array_filter($categories), 'latest' => $latest, 'complete' => $complete];
    }

    /** Timestamp ("Y-m-d H:i:s") of the newest entry, or null. */
    public function latest(string $path): ?string
    {
        foreach ($this->scan($path, null, 4 * self::BLOCK, false, headersOnly: true) as $raw) {
            if ($raw['ts'] !== null) {
                return $raw['ts'];
            }
        }

        return null;
    }

    public function category(?string $level, string $text): ?string
    {
        foreach (self::PATTERNS as $category => $pattern) {
            if (preg_match($pattern, $text)) {
                return $category;
            }
        }

        return in_array($level, ['error', 'critical', 'alert', 'emergency'], true) ? 'application' : null;
    }

    public function exceptionClass(string $text): ?string
    {
        if (preg_match('/\[object\] \(([A-Za-z_][A-Za-z0-9_]*(?:\\\\+[A-Za-z_][A-Za-z0-9_]*)*)\(code: /', $text, $m)
            || preg_match('/^(?:Next |PHP Fatal error:\s+Uncaught )?([A-Za-z_][A-Za-z0-9_]*(?:\\\\+[A-Za-z_][A-Za-z0-9_]*)+(?:Exception|Error))\b/m', $text, $m)) {
            return preg_replace('/\\\\+/', '\\', $m[1]);
        }

        return null;
    }

    // ------------------------------------------------------------------ internals

    /** Whether the end of the file holds Laravel-format entries. */
    private function structured(string $path): bool
    {
        $size = (int) @filesize($path);
        if ($size === 0) {
            return true;
        }
        $fh = fopen($path, 'rb');
        fseek($fh, max(0, $size - 65536));
        $tail = (string) fread($fh, 65536);
        fclose($fh);

        return (bool) preg_match('/^\[\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*\]\s+[^\s.:\]]+\.[A-Za-z]+:/m', $tail);
    }

    private function matching(array $raw, array $filters): ?array
    {
        $levels = $filters['levels'] ?? [];
        if ($levels && ! in_array($raw['level'], $levels, true)) {
            return null;
        }
        if ($raw['ts'] !== null && ! empty($filters['to']) && $raw['ts'] > $filters['to']) {
            return null;
        }
        if ((! empty($filters['from']) || ! empty($filters['to'])) && $raw['ts'] === null) {
            return null;
        }

        $lines = $raw['lines'];
        while ($lines && trim(end($lines)) === '') {
            array_pop($lines);
        }
        $first = $raw['message'];
        $rest = implode("\n", $lines);
        $full = $first."\n".$rest;

        $exception = $this->exceptionClass($full);
        if (! empty($filters['exception']) && ($exception === null || stripos($exception, $filters['exception']) === false)) {
            return null;
        }
        $category = $this->category($raw['level'], $full);
        if (! empty($filters['category']) && $category !== $filters['category']) {
            return null;
        }

        $q = $filters['q'] ?? null;
        if ($q !== null && $q !== '' && stripos($full, $q) === false && stripos($q, 'redacted') === false) {
            return null;
        }
        $message = $this->redactor->redact($first);
        $details = $this->redactor->redact($rest);
        if ($q !== null && $q !== '' && stripos($message."\n".$details, $q) === false) {
            return null; // matched only text that is masked
        }

        $max = (int) config('maintenance.logs.entry_max_chars', 20000);
        $truncated = $raw['truncated'] || mb_strlen($details) > $max || mb_strlen($message) > 4000;

        return [
            'offset' => $raw['offset'],
            'timestamp' => $raw['ts'],
            'environment' => $raw['env'],
            'level' => $raw['level'],
            'message' => mb_substr($message, 0, 4000),
            'details' => mb_substr($details, 0, $max),
            'exception' => $exception,
            'category' => $category,
            'truncated' => $truncated,
        ];
    }

    /**
     * Yields raw entries newest first. Returns ['resume' => ?int, 'reached_start' => bool,
     * 'limited' => bool, 'scanned' => int] when the start of the file or the byte limit is reached.
     */
    private function scan(string $path, ?int $before, int $maxScan, bool $plain, bool $headersOnly = false): Generator
    {
        $fh = fopen($path, 'rb');
        $size = (int) filesize($path);
        $pos = $before === null ? $size : max(0, min($before, $size));
        $resume = $pos;
        $carry = '';
        $pending = [];
        $pendingBytes = 0;
        $truncated = false;
        $scanned = 0;

        try {
            while ($pos > 0) {
                if ($scanned >= $maxScan) {
                    return ['resume' => $resume, 'reached_start' => false, 'limited' => true, 'scanned' => $scanned];
                }
                $read = min(self::BLOCK, $pos);
                $pos -= $read;
                fseek($fh, $pos);
                $block = (string) fread($fh, $read);
                $scanned += $read;

                $lines = explode("\n", $block.$carry);
                $first = $pos > 0 ? array_shift($lines) : null;
                $offset = $pos + ($first !== null ? strlen($first) + 1 : 0);
                $starts = [];
                foreach ($lines as $i => $line) {
                    $starts[$i] = $offset;
                    $offset += strlen($line) + 1;
                }

                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $line = rtrim($lines[$i], "\r");
                    $head = $plain ? null : $this->header($line);
                    if ($head !== null) {
                        $resume = $starts[$i];
                        yield [
                            'offset' => $starts[$i], 'ts' => $head['ts'], 'env' => $head['env'], 'level' => $head['level'],
                            'line' => substr($line, 0, 8192), 'message' => substr($line, $head['prefix']),
                            'lines' => $headersOnly ? [] : array_reverse($pending), 'truncated' => $truncated,
                        ];
                        $pending = [];
                        $pendingBytes = 0;
                        $truncated = false;
                    } elseif ($plain) {
                        $resume = $starts[$i];
                        if ($line !== '') {
                            yield ['offset' => $starts[$i], 'ts' => null, 'env' => null, 'level' => null, 'line' => substr($line, 0, 8192), 'message' => $line, 'lines' => [], 'truncated' => false];
                        }
                    } elseif (! $headersOnly) {
                        $pending[] = $line;
                        $pendingBytes += strlen($line);
                        while ($pendingBytes > self::MAX_ENTRY_LINES_BYTES && count($pending) > 1) {
                            $pendingBytes -= strlen(array_shift($pending)); // drop the end of an overlong trace
                            $truncated = true;
                        }
                    }
                }
                $carry = $first ?? '';
            }

            if (! $plain && ! $headersOnly && array_filter($pending, fn ($l) => trim($l) !== '')) {
                // The file starts in the middle of an entry.
                $lines = array_reverse($pending);
                yield ['offset' => 0, 'ts' => null, 'env' => null, 'level' => null, 'line' => '', 'message' => (string) array_shift($lines), 'lines' => $lines, 'truncated' => $truncated];
            }

            return ['resume' => null, 'reached_start' => true, 'limited' => false, 'scanned' => $scanned];
        } finally {
            fclose($fh);
        }
    }

    /** @return array{ts: string, env: string, level: string, prefix: int}|null */
    private function header(string $line): ?array
    {
        if ($line === '' || $line[0] !== '[' || ! preg_match(self::HEADER, substr($line, 0, 200), $m)) {
            return null;
        }

        return ['ts' => str_replace('T', ' ', $m[1]), 'env' => $m[2], 'level' => strtolower($m[3]), 'prefix' => strlen($m[0])];
    }
}
