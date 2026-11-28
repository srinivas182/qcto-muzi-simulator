<?php

declare(strict_types=1);

namespace App\Learners;

use Illuminate\Support\Facades\Crypt;

/**
 * POPIA-safe handling of ID numbers: encrypted with the app key (held outside the database), plus a keyed hash
 * (HMAC-SHA256) so duplicates can be found without decrypting. Never log the plain number.
 */
final class IdNumbers
{
    public static function normalise(string $raw): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $raw) ?? '');
    }

    public static function hash(string $type, string $number): string
    {
        $key = (string) (config('identity.id_hash_key') ?: hash_hmac('sha256', 'learner-id-numbers', (string) config('app.key')));

        return hash_hmac('sha256', $type.':'.self::normalise($number), $key);
    }

    /** @return array{id_number_encrypted: string, id_number_hash: string, id_last4: string} */
    public static function protect(string $type, string $number): array
    {
        $n = self::normalise($number);

        return ['id_number_encrypted' => Crypt::encryptString($n), 'id_number_hash' => self::hash($type, $n), 'id_last4' => substr($n, -4)];
    }

    public static function reveal(string $encrypted): string
    {
        return Crypt::decryptString($encrypted);
    }
}
