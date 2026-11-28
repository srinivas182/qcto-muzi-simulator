<?php

declare(strict_types=1);

namespace App\Learners;

use Illuminate\Support\Carbon;

/**
 * South African ID number: YYMMDD SSSS C A Z (13 digits).
 *  - YYMMDD: date of birth; SSSS: 0000–4999 female, 5000–9999 male; C: 0 citizen, 1 permanent resident;
 *  - Z: Luhn check digit over the first 12 digits.
 * Same rules as packages/core `checkSaId` on the web and mobile.
 */
final class SaIdNumber
{
    /** @return array{date_of_birth: string, gender: 'female'|'male', citizenship: 'sa'|'permanent_resident'}|null */
    public static function parse(string $raw): ?array
    {
        $id = preg_replace('/\s+/', '', $raw) ?? '';
        if (preg_match('/^\d{13}$/', $id) !== 1 || ! self::luhn($id) || ! in_array($id[10], ['0', '1'], true)) {
            return null;
        }
        $yy = (int) substr($id, 0, 2);
        $century = $yy > (int) now()->format('y') ? 1900 : 2000;
        $year = $century + $yy;
        $month = (int) substr($id, 2, 2);
        $day = (int) substr($id, 4, 2);
        if (! checkdate($month, $day, $year)) {
            return null;
        }
        $dob = Carbon::create($year, $month, $day);
        if ($dob === null || $dob->isFuture()) {
            return null;
        }

        return [
            'date_of_birth' => $dob->toDateString(),
            'gender' => (int) substr($id, 6, 4) < 5000 ? 'female' : 'male',
            'citizenship' => $id[10] === '0' ? 'sa' : 'permanent_resident',
        ];
    }

    public static function luhn(string $digits): bool
    {
        $sum = 0;
        $double = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $d = (int) $digits[$i];
            if ($double) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
