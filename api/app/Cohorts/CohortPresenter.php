<?php

declare(strict_types=1);

namespace App\Cohorts;

use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\User;

/** API shapes for cohorts and enrolments. ID numbers appear only masked. */
final class CohortPresenter
{
    /**
     * @param  array<string, int>  $counts  status => count for this cohort
     * @return array<string, mixed>
     */
    public static function summary(Cohort $c, array $counts = []): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'centre' => ['id' => $c->centre_id, 'name' => $c->centre->name],
            'programme' => ['id' => $c->programme_id, 'code' => $c->programme->code, 'title' => $c->programme->title],
            'version' => $c->version->number,
            'starts_on' => $c->starts_on->toDateString(),
            'ends_on' => $c->ends_on?->toDateString(),
            'qcto_type' => $c->qcto_type,
            'qcto_submitted_at' => $c->qcto_submitted_at?->toIso8601String(),
            'join_open' => $c->join_open,
            'archived' => $c->archived_at !== null,
            'learners' => ($counts[Enrolment::ENROLLED] ?? 0) + ($counts[Enrolment::ACTIVE] ?? 0),
            'counts' => (object) $counts,
            'facilitators' => $c->facilitators->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public static function enrolment(Enrolment $e, ?Cohort $cohort = null): array
    {
        $cohort ??= $e->cohort;

        return [
            'id' => $e->id,
            'learner' => ['id' => $e->learner_id, 'name' => $e->learner->fullName(), 'id_masked' => str_repeat('•', 4).$e->learner->id_last4, 'status' => $e->learner->status],
            'status' => $e->status,
            'holds_seat' => $e->holds_seat,
            'progress' => $e->progress,
            'seat_taken_at' => $e->seat_taken_at->toIso8601String(),
            'started_at' => $e->started_at?->toIso8601String(),
            'ended_at' => $e->ended_at?->toIso8601String(),
            'reason' => $e->reason,
            'seat_returns_on' => $e->status === Enrolment::ENROLLED && $e->started_at === null ? $e->noShowDate($cohort)->toDateString() : null,
        ];
    }
}
