<?php

namespace App\Mail\Pool;

/**
 * Removes SMTP logins and passwords (and their encoded forms) from text before it is stored,
 * logged or shown. Server replies and transport errors can quote the login, and an SMTP
 * transcript carries the password base64-encoded.
 */
final class MailSecrets
{
    /** @param  array<string, string>  $replacements  secret => placeholder */
    private function __construct(private array $replacements) {}

    public static function fromConfig(): self
    {
        $accounts = array_merge(array_values((array) config('mail.mailers', [])), array_values((array) config('mail.pool.accounts', [])));
        $map = [];
        foreach ($accounts as $c) {
            $user = is_array($c) ? (string) ($c['username'] ?? '') : '';
            $pass = is_array($c) ? (string) ($c['password'] ?? '') : '';
            if ($pass !== '') {
                foreach ([$pass, base64_encode($pass), rawurlencode($pass), base64_encode("\0$user\0$pass"), base64_encode("$user\0$user\0$pass")] as $v) {
                    $map[$v] = '[redacted]';
                }
            }
            if ($user !== '') {
                $map[$user] = '[login]';
                $map[base64_encode($user)] = '[login]';
            }
        }
        // Longest first, so a password containing the login is removed whole.
        uksort($map, fn ($a, $b) => strlen($b) <=> strlen($a));

        return new self($map);
    }

    public function redact(?string $text, int $limit = 500): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }
        $text = strtr($text, $this->replacements);
        $text = preg_replace('/\bAUTH\s+(PLAIN|LOGIN|XOAUTH2|CRAM-MD5)\s+\S+/i', 'AUTH $1 [redacted]', $text);
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1).'…' : $text;
    }
}
