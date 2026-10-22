<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class MaxWords implements ValidationRule
{
    public function __construct(private int $max) {}

    public static function count(?string $text): int
    {
        $text = trim((string) $text);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text));
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $words = self::count($value);
        if ($words > $this->max) {
            $fail("The :attribute must not exceed {$this->max} words (currently {$words}).");
        }
    }
}
