<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Audit\AuditLogger;
use App\Models\Enrolment;
use App\Models\User;

/**
 * Assessment accommodations (Sprint 5.2b): extra time on tests and read-aloud of questions, set per learner by
 * someone who signs off assessments. Only the settings are kept, never the reason (it may be health information).
 */
final class Accommodations
{
    public const EXTRA_TIME = [0, 25, 50, 100];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{extra_time: int, read_aloud: bool} */
    public static function of(Enrolment $e): array
    {
        $a = (array) ($e->accommodations ?? []);
        $extra = (int) ($a['extra_time'] ?? 0);

        return ['extra_time' => in_array($extra, self::EXTRA_TIME, true) ? $extra : 0, 'read_aloud' => (bool) ($a['read_aloud'] ?? false)];
    }

    /**
     * @param  array{extra_time: int, read_aloud: bool}  $next
     * @return array{extra_time: int, read_aloud: bool}
     */
    public function set(Enrolment $e, array $next, User $actor): array
    {
        $before = self::of($e);
        $after = ['extra_time' => $next['extra_time'], 'read_aloud' => $next['read_aloud']];
        $e->forceFill(['accommodations' => $after === ['extra_time' => 0, 'read_aloud' => false] ? null : $after])->save();
        $this->audit->record('assessments.accommodations', $e, ['from' => $before, 'to' => $after], [], $actor);

        return $after;
    }

    /** Test minutes with the learner's extra time. */
    public static function minutes(int $minutes, Enrolment $e): int
    {
        return (int) ceil($minutes * (100 + self::of($e)['extra_time']) / 100);
    }
}
