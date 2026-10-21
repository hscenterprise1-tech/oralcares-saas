<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A telephone number as people type it, in India or abroad: an optional
 * leading `+`, then digits that may be grouped with spaces, hyphens or
 * brackets. Between 7 and 15 digits (the ITU E.164 maximum); letters and
 * other symbols are refused. Numbers are stored as typed; duplicate
 * detection compares them without their separators.
 */
class PhoneNumber implements ValidationRule
{
    public const MIN_DIGITS = 7;

    public const MAX_DIGITS = 15;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = trim((string) $value);
        $digits = strlen((string) preg_replace('/\D/', '', $value));

        if (preg_match('/^\+?[0-9][0-9\s\-()]*$/', $value) !== 1 || $digits < self::MIN_DIGITS || $digits > self::MAX_DIGITS) {
            $fail('Enter a valid phone number: digits only, optionally starting with + and a country code (7 to 15 digits).');
        }
    }
}
