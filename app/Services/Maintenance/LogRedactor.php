<?php

namespace App\Services\Maintenance;

use App\Mail\Pool\MailSecrets;
use App\Support\IdentityNumbers;

/**
 * Masks secrets and sensitive identity numbers in log text before it is shown in the browser or
 * stored as an error message: configured secrets (APP_KEY, database, Redis, mail and backup
 * credentials), password/token/secret/cookie/session values, bearer and basic credentials, URL
 * passwords, Laravel keys, 64-hex link tokens, JWTs, password hashes, Aadhaar and PAN numbers.
 * Masking is best effort; the original files are only downloadable with logs.download.
 */
class LogRedactor
{
    private const SENSITIVE_KEY = '[A-Za-z0-9_.-]*(?:password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|private[_-]?key|app[_-]?key|encryption[_-]?key|authorization|cookie|session|otp|verification[_-]?code)[A-Za-z0-9_.-]*';

    /** @var array<string, string>|null */
    private ?array $literals = null;

    private ?MailSecrets $mail = null;

    public function redact(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        $text = strtr($text, $this->literals());
        $text = ($this->mail ??= MailSecrets::fromConfig())->strip($text);

        $text = preg_replace([
            // key: value / key=value / "key":"value" / 'key' => 'value' (JSON-escaped quotes included)
            '/(["\']?)\b('.self::SENSITIVE_KEY.')\1(\s*(?:=>|:|=)\s*)("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\s,;&}\]\)]+)/i',
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/-]{8,}=*/i',
            '/\bbase64:[A-Za-z0-9+\/]{20,}={0,2}/',
            '/\b([a-z][a-z0-9+.-]*:\/\/)([^\s:\/@]+):([^\s@\/]+)@/i',
            '/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}/',
            '/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/',
            '/\$argon2(?:i|id|d)\$[^\s"\',]+/',
            '/(?<![A-Za-z0-9])[a-f0-9]{64}(?![A-Za-z0-9])/i',
        ], [
            '$1$2$1$3"[redacted]"',
            '$1 [redacted]',
            'base64:[redacted]',
            '$1$2:[redacted]@',
            '[redacted-jwt]',
            '[redacted-hash]',
            '[redacted-hash]',
            '[redacted-token]',
        ], $text) ?? $text;

        // Aadhaar (12 digits, optionally grouped 4-4-4) and PAN: keep only the last four characters.
        $text = preg_replace_callback('/(?<![\d.\/:-])\d{4}[ -]?\d{4}[ -]?\d{4}(?![\d.\/:-])/', fn ($m) => IdentityNumbers::maskAadhaar(preg_replace('/\D/', '', $m[0])), $text) ?? $text;

        return preg_replace_callback('/\b[A-Z]{5}\d{4}[A-Z]\b/', fn ($m) => IdentityNumbers::maskPan($m[0]), $text) ?? $text;
    }

    /** @return array<string, string> configured secret values => placeholder, longest first */
    private function literals(): array
    {
        if ($this->literals !== null) {
            return $this->literals;
        }
        $values = [config('app.key'), config('maintenance.backup.key'), ...(array) config('app.previous_keys', []), ...(array) config('maintenance.backup.previous_keys', [])];
        foreach ((array) config('database.connections', []) as $c) {
            $values[] = $c['password'] ?? null;
        }
        foreach ((array) config('database.redis', []) as $c) {
            $values[] = is_array($c) ? ($c['password'] ?? null) : null;
        }
        foreach ((array) config('services', []) as $service) {
            foreach ((array) $service as $k => $v) {
                if (is_string($v) && preg_match('/secret|key|token|password/i', (string) $k)) {
                    $values[] = $v;
                }
            }
        }

        $map = [];
        foreach ($values as $v) {
            if (! is_string($v) || strlen($v) < 6) {
                continue;
            }
            $map[$v] = '[redacted]';
            if (str_starts_with($v, 'base64:')) {
                $map[substr($v, 7)] = '[redacted]';
            }
        }
        uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $this->literals = $map;
    }
}
