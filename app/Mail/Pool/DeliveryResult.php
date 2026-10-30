<?php

namespace App\Mail\Pool;

/** What became of one delivery run: sent, retry after a delay, failed for good, or nothing to do. */
final class DeliveryResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly int $delay = 0,
        public readonly ?string $reason = null,
    ) {}

    public static function sent(): self
    {
        return new self('sent');
    }

    public static function retry(int $delay): self
    {
        return new self('retry', $delay);
    }

    public static function failed(string $reason): self
    {
        return new self('failed', 0, $reason);
    }

    /** Already sent by another run. */
    public static function done(): self
    {
        return new self('done');
    }
}
