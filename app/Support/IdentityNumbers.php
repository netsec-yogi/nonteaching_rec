<?php

namespace App\Support;

/**
 * PAN / Aadhaar numbers: normalisation, format checks and masking. Format checks only — a number
 * that passes is well formed, not verified with the Income Tax Department or UIDAI.
 */
final class IdentityNumbers
{
    public const PAN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public static function normalisePan(?string $value): ?string
    {
        $v = strtoupper(preg_replace('/\s+/', '', (string) $value));

        return $v === '' ? null : $v;
    }

    public static function normaliseAadhaar(?string $value): ?string
    {
        $v = preg_replace('/[\s-]+/', '', (string) $value);

        return $v === '' ? null : $v;
    }

    /** 12 digits, not starting with 0 or 1, with a valid Verhoeff check digit (the published Aadhaar format). */
    public static function aadhaarFormatValid(string $value): bool
    {
        if (! preg_match('/^[2-9][0-9]{11}$/', $value)) {
            return false;
        }
        $d = [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 2, 3, 4, 0, 6, 7, 8, 9, 5], [2, 3, 4, 0, 1, 7, 8, 9, 5, 6], [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
            [4, 0, 1, 2, 3, 9, 5, 6, 7, 8], [5, 9, 8, 7, 6, 0, 4, 3, 2, 1], [6, 5, 9, 8, 7, 1, 0, 4, 3, 2], [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
            [8, 7, 6, 5, 9, 3, 2, 1, 0, 4], [9, 8, 7, 6, 5, 4, 3, 2, 1, 0]];
        $p = [[0, 1, 2, 3, 4, 5, 6, 7, 8, 9], [1, 5, 7, 6, 2, 8, 3, 0, 9, 4], [5, 8, 0, 3, 7, 9, 6, 1, 4, 2], [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
            [9, 4, 5, 3, 1, 2, 6, 8, 7, 0], [4, 2, 8, 6, 5, 7, 3, 9, 0, 1], [2, 7, 9, 3, 8, 0, 6, 4, 1, 5], [7, 0, 4, 6, 9, 1, 3, 2, 5, 8]];
        $c = 0;
        foreach (array_reverse(str_split($value)) as $i => $digit) {
            $c = $d[$c][$p[$i % 8][(int) $digit]];
        }

        return $c === 0;
    }

    /** ABCDE1234F → XXXXXX234F (last four shown). */
    public static function maskPan(?string $value): ?string
    {
        return $value ? str_repeat('X', max(0, strlen($value) - 4)).substr($value, -4) : null;
    }

    /** 234567890124 → XXXX XXXX 0124 (UIDAI's masked form). */
    public static function maskAadhaar(?string $value): ?string
    {
        return $value ? 'XXXX XXXX '.substr($value, -4) : null;
    }
}
