<?php

namespace App\Services\Maintenance;

use RuntimeException;

/**
 * Streams SQL into a backup file: gzip-compressed and, with a key, encrypted with libsodium's
 * secretstream (XChaCha20-Poly1305) in 1 MiB chunks. Each chunk is authenticated and the last
 * carries the FINAL tag, so a truncated, reordered or altered file is detected when read.
 *
 * Encrypted layout: "NTRBAK01" · key id (8 bytes) · stream header (24 bytes) ·
 * repeated [uint32 big-endian length · ciphertext]. Unencrypted: a plain .sql.gz.
 * The file is created exclusively (never overwrites) with mode 0600.
 */
final class BackupWriter
{
    public const MAGIC = 'NTRBAK01';

    public const CHUNK = 1048576;

    /** @var resource|null */
    private $handle;

    private mixed $deflate;

    private ?string $state = null;

    private string $buffer = '';

    private \HashContext $hash;

    private int $written = 0;

    private int $plainBytes = 0;

    public function __construct(private readonly string $path, ?string $key = null, ?string $keyId = null)
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The backup file could not be created (check that the backup directory is writable).');
        }
        $this->handle = $handle;
        @chmod($path, 0600);
        $this->hash = hash_init('sha256');
        $this->deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

        if ($key !== null) {
            [$this->state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            $this->emit(self::MAGIC.hex2bin((string) $keyId).$header);
        }
    }

    public function write(string $data): void
    {
        if ($data === '') {
            return;
        }
        $this->plainBytes += strlen($data);
        $this->buffer .= deflate_add($this->deflate, $data, ZLIB_NO_FLUSH);
        while (strlen($this->buffer) >= self::CHUNK) {
            $this->chunk(substr($this->buffer, 0, self::CHUNK), false);
            $this->buffer = substr($this->buffer, self::CHUNK);
        }
    }

    /** @return array{size: int, sha256: string, dump_bytes: int} */
    public function finish(): array
    {
        $this->buffer .= deflate_add($this->deflate, '', ZLIB_FINISH);
        while (strlen($this->buffer) > self::CHUNK) {
            $this->chunk(substr($this->buffer, 0, self::CHUNK), false);
            $this->buffer = substr($this->buffer, self::CHUNK);
        }
        $this->chunk($this->buffer, true);
        $this->buffer = '';
        fflush($this->handle);
        if (function_exists('fsync')) {
            @fsync($this->handle);
        }
        fclose($this->handle);
        $this->handle = null;

        return ['size' => $this->written, 'sha256' => hash_final($this->hash), 'dump_bytes' => $this->plainBytes];
    }

    /** Close and remove the file (a failed or abandoned backup leaves nothing behind). */
    public function abort(): void
    {
        if ($this->handle) {
            @fclose($this->handle);
            $this->handle = null;
        }
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function bytesWritten(): int
    {
        return $this->written;
    }

    public function path(): string
    {
        return $this->path;
    }

    private function chunk(string $plain, bool $final): void
    {
        if ($this->state === null) {
            $this->emit($plain);

            return;
        }
        $tag = $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
        $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($this->state, $plain, '', $tag);
        $this->emit(pack('N', strlen($cipher)).$cipher);
    }

    private function emit(string $bytes): void
    {
        if ($bytes === '') {
            return;
        }
        $n = @fwrite($this->handle, $bytes);
        if ($n !== strlen($bytes)) {
            throw new RuntimeException('Writing the backup file failed (is the disk full?).');
        }
        hash_update($this->hash, $bytes);
        $this->written += $n;
    }
}
