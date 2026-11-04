<?php

namespace App\Services\Maintenance;

use RuntimeException;

/**
 * Encryption keys for database backups. The current key is BACKUP_ENCRYPTION_KEY, or one derived
 * from APP_KEY when that is not set. Each backup records the key's id (a keyed hash of the key,
 * not the key), so a backup made under an earlier key is opened with that key: BACKUP_PREVIOUS_KEYS,
 * or APP_PREVIOUS_KEYS for derived keys.
 */
class BackupKeys
{
    private const DERIVATION_INFO = 'ntrec-database-backup-v1';

    /** @return array{0: string, 1: string} the raw 32-byte key and its id */
    public function current(): array
    {
        $configured = $this->decode(config('maintenance.backup.key'));
        $key = $configured ?? $this->derive(config('app.key'));
        if ($key === null) {
            throw new RuntimeException('No backup encryption key: set BACKUP_ENCRYPTION_KEY or APP_KEY.');
        }

        return [$key, self::id($key)];
    }

    public function find(string $id): ?string
    {
        foreach ($this->candidates() as $key) {
            if (hash_equals(self::id($key), $id)) {
                return $key;
            }
        }

        return null;
    }

    public static function id(string $key): string
    {
        return substr(hash_hmac('sha256', 'ntrec-backup-key-id', $key), 0, 16);
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $keys = [$this->decode(config('maintenance.backup.key'))];
        foreach ((array) config('maintenance.backup.previous_keys', []) as $previous) {
            $keys[] = $this->decode(trim((string) $previous));
        }
        $keys[] = $this->derive(config('app.key'));
        foreach ((array) config('app.previous_keys', []) as $previous) {
            $keys[] = $this->derive($previous);
        }

        return array_values(array_unique(array_filter($keys, fn ($k) => $k !== null)));
    }

    private function decode(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        $raw = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : $value;
        if (! is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be 32 bytes, written as base64:… (php -r \'echo "base64:".base64_encode(random_bytes(32));\').');
        }

        return $raw;
    }

    private function derive(mixed $appKey): ?string
    {
        if (! is_string($appKey) || $appKey === '') {
            return null;
        }
        $material = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;

        return $material ? hash_hkdf('sha256', $material, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, self::DERIVATION_INFO) : null;
    }
}
