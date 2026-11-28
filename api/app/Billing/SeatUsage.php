<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\LicencePool;

/**
 * How many seats of a pool are taken. Enrolments arrive in Sprint 3.6; until then no seat is in use.
 * Kept in one place so the seat rules (no-show return, early withdrawal) change only here.
 */
final class SeatUsage
{
    /** @var (callable(LicencePool): int)|null */
    private static $resolver = null;

    public static function used(LicencePool $pool): int
    {
        return self::$resolver !== null ? (self::$resolver)($pool) : 0;
    }

    /** @param callable(LicencePool): int $resolver */
    public static function resolveUsing(callable $resolver): void
    {
        self::$resolver = $resolver;
    }
}
