<?php

namespace App\Services\Maintenance;

use Generator;

/**
 * Reads a backup written by BackupWriter (or a plain .sql.gz from the earlier db:backup): decrypts
 * and authenticates each chunk, decompresses, and refuses anything truncated, altered, encrypted
 * under an unknown key or not in a known format. Reading never loads the whole file into memory.
 */
final class BackupReader
{
    private const SLICE = 65536;

    /** @var resource|null */
    private $handle;

    private bool $encrypted;

    private ?string $keyId = null;

    private ?string $state = null;

    public function __construct(string $path, BackupKeys $keys)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new BackupIntegrityException('The backup file is missing or cannot be read.');
        }
        $handle = fopen($path, 'rb');
        $magic = (string) fread($handle, strlen(BackupWriter::MAGIC));

        if ($magic === BackupWriter::MAGIC) {
            $id = (string) fread($handle, 8);
            $header = (string) fread($handle, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (strlen($id) !== 8 || strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                fclose($handle);
                throw new BackupIntegrityException('The backup file is truncated (incomplete header).');
            }
            $this->keyId = bin2hex($id);
            $key = $keys->find($this->keyId);
            if ($key === null) {
                fclose($handle);
                throw new BackupIntegrityException('This backup was encrypted with a key this server does not have. Set BACKUP_ENCRYPTION_KEY (or BACKUP_PREVIOUS_KEYS / APP_PREVIOUS_KEYS) to the key in use when it was made.');
            }
            $this->state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $this->encrypted = true;
        } elseif (str_starts_with($magic, "\x1f\x8b")) {
            rewind($handle);
            $this->encrypted = false;
        } else {
            fclose($handle);
            throw new BackupIntegrityException('This is not a backup file created by the portal (unknown format).');
        }
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->close();
    }

    public function close(): void
    {
        if ($this->handle) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function encrypted(): bool
    {
        return $this->encrypted;
    }

    public function keyId(): ?string
    {
        return $this->keyId;
    }

    /**
     * Check the whole file: checksum (when given), every chunk's authentication, complete
     * decompression, and mysqldump's completion marker at the end of the SQL.
     *
     * @return array{dump_bytes: int}
     */
    public static function verify(string $path, ?string $expectedSha256, BackupKeys $keys, ?callable $tick = null): array
    {
        if ($expectedSha256 !== null) {
            $actual = is_file($path) ? hash_file('sha256', $path) : false;
            if ($actual === false) {
                throw new BackupIntegrityException('The backup file is missing or cannot be read.');
            }
            if (! hash_equals($expectedSha256, $actual)) {
                throw new BackupIntegrityException('The file\'s SHA-256 checksum does not match the one recorded when it was made: it has been altered or damaged.');
            }
        }

        $reader = new self($path, $keys);
        $bytes = 0;
        $tail = '';
        foreach ($reader->sql($tick) as $piece) {
            $bytes += strlen($piece);
            $tail = substr($tail.$piece, -512);
        }
        $reader->close();

        if (! preg_match('/-- Dump completed[^\n]*\s*$/', $tail)) {
            throw new BackupIntegrityException('The SQL does not end with mysqldump\'s completion marker: the dump is incomplete.');
        }

        return ['dump_bytes' => $bytes];
    }

    /** The SQL text, decompressed, in pieces of a few hundred KiB at most. */
    public function sql(?callable $tick = null): Generator
    {
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        foreach ($this->compressed($tick) as $piece) {
            for ($offset = 0, $length = strlen($piece); $offset < $length; $offset += self::SLICE) {
                if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                    throw new BackupIntegrityException('The backup has unexpected data after its end.');
                }
                $out = $this->inflate($inflate, substr($piece, $offset, self::SLICE));
                if ($out !== '') {
                    yield $out;
                }
            }
        }
        if (inflate_get_status($inflate) !== ZLIB_STREAM_END) {
            throw new BackupIntegrityException('The compressed data ends early: the backup is truncated.');
        }
    }

    /** The stored stream after decryption (still gzip-compressed): what a plain .sql.gz holds. */
    public function compressed(?callable $tick = null): Generator
    {
        if (! $this->encrypted) {
            while (! feof($this->handle)) {
                $data = fread($this->handle, BackupWriter::CHUNK);
                if ($data === false) {
                    throw new BackupIntegrityException('Reading the backup file failed.');
                }
                if ($data !== '') {
                    yield $data;
                }
                $tick && $tick();
            }

            return;
        }

        while (true) {
            $length = (string) fread($this->handle, 4);
            if ($length === '' && feof($this->handle)) {
                throw new BackupIntegrityException('The backup ends early: it is truncated.');
            }
            if (strlen($length) !== 4) {
                throw new BackupIntegrityException('The backup is truncated (incomplete chunk).');
            }
            $size = unpack('N', $length)[1];
            if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES || $size > BackupWriter::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES) {
                throw new BackupIntegrityException('The backup is corrupt (invalid chunk length).');
            }
            $cipher = $this->readExactly($size);
            $result = sodium_crypto_secretstream_xchacha20poly1305_pull($this->state, $cipher);
            if ($result === false) {
                throw new BackupIntegrityException('Part of the backup failed authentication: the file was altered or is corrupt.');
            }
            [$plain, $tag] = $result;
            if ($plain !== '') {
                yield $plain;
            }
            $tick && $tick();

            if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                if (fread($this->handle, 1) !== '') {
                    throw new BackupIntegrityException('The backup has unexpected data after its end.');
                }

                return;
            }
        }
    }

    private function readExactly(int $size): string
    {
        $data = '';
        while (strlen($data) < $size && ! feof($this->handle)) {
            $part = fread($this->handle, $size - strlen($data));
            if ($part === false || $part === '') {
                break;
            }
            $data .= $part;
        }
        if (strlen($data) !== $size) {
            throw new BackupIntegrityException('The backup is truncated (incomplete chunk).');
        }

        return $data;
    }

    private function inflate(mixed $context, string $data): string
    {
        $out = @inflate_add($context, $data, ZLIB_SYNC_FLUSH);
        if ($out === false) {
            throw new BackupIntegrityException('The compressed data in the backup is corrupt.');
        }

        return $out;
    }
}
