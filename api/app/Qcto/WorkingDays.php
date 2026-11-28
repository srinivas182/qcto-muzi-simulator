<?php

declare(strict_types=1);

namespace App\Qcto;

use Illuminate\Support\Carbon;

/**
 * South African working days: Monday to Friday, excluding public holidays (Public Holidays Act 36 of 1994).
 * A holiday on a Sunday moves to the Monday. QCTO's enrolment upload is due 21 working days after training starts.
 */
final class WorkingDays
{
    /** The date $days working days after $from (the start day itself is not counted). */
    public static function add(Carbon $from, int $days): Carbon
    {
        $d = $from->copy()->startOfDay();
        while ($days > 0) {
            $d->addDay();
            if (self::isWorkingDay($d)) {
                $days--;
            }
        }

        return $d;
    }

    /** Working days from $from up to $to (positive when $to is later, 0 when the same day). */
    public static function between(Carbon $from, Carbon $to): int
    {
        $a = $from->copy()->startOfDay();
        $b = $to->copy()->startOfDay();
        $sign = $b->gte($a) ? 1 : -1;
        [$a, $b] = $sign === 1 ? [$a, $b] : [$b, $a];
        $n = 0;
        while ($a->lt($b)) {
            $a->addDay();
            if (self::isWorkingDay($a)) {
                $n++;
            }
        }

        return $sign * $n;
    }

    public static function isWorkingDay(Carbon $d): bool
    {
        return ! $d->isWeekend() && ! in_array($d->toDateString(), self::holidays($d->year), true);
    }

    /** @return list<string> */
    public static function holidays(int $year): array
    {
        $easter = self::easter($year);
        $fixed = ['01-01', '03-21', '04-27', '05-01', '06-16', '08-09', '09-24', '12-16', '12-25', '12-26'];
        $days = [$easter->copy()->subDays(2)->toDateString(), $easter->copy()->addDay()->toDateString()]; // Good Friday, Family Day
        foreach ($fixed as $md) {
            $d = Carbon::parse("{$year}-{$md}");
            $days[] = $d->toDateString();
            if ($d->isSunday()) {
                $days[] = $d->copy()->addDay()->toDateString();
            }
        }

        return $days;
    }

    /** Easter Sunday (Gregorian), without needing PHP's calendar extension. */
    private static function easter(int $y): Carbon
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $h = (19 * $a + $b - intdiv($b, 4) - intdiv(8 * $b + 13, 25) + 15) % 30;
        $l = (32 + 2 * ($b % 4) + 2 * intdiv($c, 4) - $h - $c % 4) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($y, $month, $day)->startOfDay();
    }
}
