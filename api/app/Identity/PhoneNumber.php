<?php

declare(strict_types=1);

namespace App\Identity;

/**
 * Normalises phone numbers to E.164. South African local formats are accepted:
 * 082 123 4567, 0821234567, 27821234567, +27 82 123 4567 → +27821234567.
 */
final class PhoneNumber
{
    public static function normalise(string $input, string $defaultCountryCode = '27'): ?string
    {
        $digits = preg_replace('/\D+/', '', $input) ?? '';
        $hasPlus = str_starts_with(trim($input), '+');

        if ($digits === '') {
            return null;
        }

        if (! $hasPlus) {
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            } elseif (str_starts_with($digits, '0')) {
                $digits = $defaultCountryCode.substr($digits, 1);
            }
        }

        if (! preg_match('/^[1-9]\d{7,14}$/', $digits)) {
            return null;
        }

        // South African mobile/landline numbers have 9 digits after the country code.
        if (str_starts_with($digits, '27') && strlen($digits) !== 11) {
            return null;
        }

        return '+'.$digits;
    }

    /** +27821234567 → +27 •• ••• 4567 (for showing which number a code went to). */
    public static function mask(string $e164): string
    {
        return substr($e164, 0, 3).' •• ••• '.substr($e164, -4);
    }
}
