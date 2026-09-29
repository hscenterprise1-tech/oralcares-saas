<?php

namespace App\Services\Marketing;

class PhoneNormalizer
{
    /**
     * Normalize a raw phone number for consistent duplicate matching.
     * Supports Indian mobile formats (+91 9876543210, 919876543210, 09876543210, 9876543210).
     */
    public static function normalize(?string $rawPhone): string
    {
        if (empty($rawPhone)) {
            return '';
        }

        // Remove spaces, hyphens, brackets, dots, and plus signs
        $digits = preg_replace('/[^\d]/', '', $rawPhone);

        if (empty($digits)) {
            return '';
        }

        // Handle Indian 10-digit mobile number variations
        // Case 1: Starts with 91 and 12 digits total (e.g. 919876543210 -> 9876543210)
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            return substr($digits, 2);
        }

        // Case 2: Starts with 0 and 11 digits total (e.g. 09876543210 -> 9876543210)
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            return substr($digits, 1);
        }

        // Case 3: Exactly 10 digits
        if (strlen($digits) === 10) {
            return $digits;
        }

        // Fallback for other international formats
        return $digits;
    }

    /**
     * Validate whether a phone number is structurally plausible.
     */
    public static function isValid(?string $rawPhone): bool
    {
        $normalized = static::normalize($rawPhone);

        if (strlen($normalized) < 7 || strlen($normalized) > 15) {
            return false;
        }

        return true;
    }
}
