<?php

declare(strict_types=1);

namespace App\Learning;

use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Enrolment;
use Illuminate\Support\Collection;

/**
 * A learner's progress on their programme: the weighted share of required, published activities of the
 * enrolment's pinned version that they have completed at least once. This number drives the 10% withdrawal
 * rule, the learner's home screen and (4.6) the at-risk view.
 */
final class Progress
{
    /** @return Collection<int, Activity> */
    public static function activities(Enrolment $e): Collection
    {
        return Activity::query()->where('programme_version_id', $e->programme_version_id)->where('status', Activity::PUBLISHED)->orderBy('position')->get();
    }

    public static function percent(Enrolment $e): int
    {
        $required = self::activities($e)->where('required', true);
        $total = $required->sum('weight');
        if ($total === 0) {
            return 0;
        }
        $done = Attempt::query()->where('enrolment_id', $e->id)->where('completed', true)->whereIn('activity_id', $required->pluck('id'))->distinct()->pluck('activity_id')->flip();
        $earned = $required->filter(fn (Activity $a) => $done->has($a->id))->sum('weight');

        return (int) floor($earned * 100 / $total);
    }

    public static function refresh(Enrolment $e): int
    {
        $p = self::percent($e);
        $e->update(['progress' => $p, 'last_activity_at' => now()]);

        return $p;
    }

    /**
     * Each activity with this learner's status on it.
     *
     * @return list<array<string, mixed>>
     */
    public static function board(Enrolment $e): array
    {
        $attempts = Attempt::query()->where('enrolment_id', $e->id)->get()->groupBy('activity_id');

        return self::activities($e)->map(function (Activity $a) use ($attempts): array {
            /** @var Collection<int, Attempt> $mine */
            $mine = $attempts->get($a->id, collect());

            return [
                'id' => $a->id, 'kind' => $a->kind, 'code' => $a->code, 'title' => $a->title, 'summary' => $a->summary, 'minutes' => $a->minutes,
                'required' => $a->required, 'content_version' => $a->content_version, 'outcomes' => $a->outcomeCodes(),
                'status' => $mine->contains('completed', true) ? 'done' : ($mine->isNotEmpty() ? 'started' : 'new'),
                'best_score' => $mine->max('score'),
                'attempts' => $mine->count(),
            ];
        })->values()->all();
    }
}
