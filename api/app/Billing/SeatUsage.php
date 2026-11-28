<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Enrolment;
use App\Models\LicencePool;

/**
 * How many seats of a pool are taken: enrolments that still hold their seat. A seat is given back by the
 * no-show and early-withdrawal rules (see Enrolment); the database refuses to overfill a pool.
 */
final class SeatUsage
{
    public static function used(LicencePool $pool): int
    {
        return Enrolment::query()->where('licence_pool_id', $pool->id)->where('holds_seat', true)->count();
    }
}
