<?php

declare(strict_types=1);

namespace App\Learning;

use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Cohort;
use App\Models\Enrolment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What facilitators and learners see about progress:
 *  - the cohort board: every learner × every published activity, with status and best score;
 *  - at risk: a learner who has started but has done nothing for 7 days (or never did anything 7 days after
 *    starting). This feeds the cohort list and the reminders;
 *  - the job-ready checklist: each QCTO outcome of the programme, marked
 *      shown      a real-job simulator run covering it was passed,
 *      practised  a practice run was passed, or a lesson covering it was finished with 70% or more,
 *      not yet.
 * Everything is computed from attempts in a few grouped queries, so a cohort of 200 stays fast.
 */
final class CohortProgress
{
    public const AT_RISK_DAYS = 7;

    public const PRACTISED_LESSON_SCORE = 70;

    /** A learner who started and then went quiet for a week. */
    public static function atRisk(Enrolment $e, ?Carbon $now = null): bool
    {
        $now ??= now();
        if (! in_array($e->status, [Enrolment::ENROLLED, Enrolment::ACTIVE], true) || $e->started_at === null) {
            return false;
        }
        $last = $e->last_activity_at ?? $e->started_at;

        return $last->lt($now->copy()->subDays(self::AT_RISK_DAYS));
    }

    /** @return array<string, mixed> */
    public function board(Cohort $cohort): array
    {
        $activities = Activity::query()->where('programme_version_id', $cohort->programme_version_id)->where('status', Activity::PUBLISHED)
            ->orderBy('position')->get(['id', 'kind', 'code', 'title', 'required']);
        $enrolments = Enrolment::query()->with('learner:id,first_names,surname')->where('cohort_id', $cohort->id)
            ->whereIn('status', [Enrolment::ENROLLED, Enrolment::ACTIVE, Enrolment::COMPLETED])->get();
        $cells = $this->cells($enrolments->pluck('id')->all());
        $outcomes = $this->outcomeCount($cohort->programme_version_id);

        $rows = $enrolments->map(function (Enrolment $e) use ($activities, $cells, $outcomes): array {
            $mine = $cells->get($e->id, collect());
            $shown = count(array_filter($this->checklistFor($e, $mine), fn ($s) => $s === 'shown'));

            return [
                'id' => $e->id, 'name' => $e->learner->fullName(), 'status' => $e->status, 'progress' => $e->progress,
                'last_activity_at' => $e->last_activity_at?->toIso8601String(), 'at_risk' => self::atRisk($e),
                'job_ready' => ['shown' => $shown, 'of' => $outcomes],
                'cells' => $activities->mapWithKeys(function (Activity $a) use ($mine): array {
                    $c = $mine->get($a->id);

                    return [$a->id => $c === null ? null : ['status' => $c->done ? 'done' : 'started', 'best' => $c->best === null ? null : (int) $c->best, 'tries' => (int) $c->tries, 'job_passed' => (bool) $c->job_passed]];
                })->all(),
            ];
        })->sortBy([['at_risk', 'desc'], ['progress', 'asc'], ['name', 'asc']])->values();

        return [
            'activities' => $activities->map(fn (Activity $a) => ['id' => $a->id, 'kind' => $a->kind, 'code' => $a->code, 'title' => $a->title, 'required' => $a->required])->all(),
            'learners' => $rows->all(),
            'summary' => [
                'learners' => $rows->count(), 'average' => (int) round((float) $rows->avg('progress')),
                'at_risk' => $rows->where('at_risk', true)->count(),
                'job_ready' => $rows->filter(fn ($r) => $r['job_ready']['of'] > 0 && $r['job_ready']['shown'] === $r['job_ready']['of'])->count(),
            ],
        ];
    }

    /**
     * The job-ready checklist of one enrolment, grouped by duty area.
     *
     * @return list<array{number: int, title: string, outcomes: list<array{code: string, statement: string, plain_words: string|null, status: string}>}>
     */
    public function checklist(Enrolment $e): array
    {
        $status = $this->checklistFor($e, $this->cells([$e->id])->get($e->id, collect()));
        $rows = DB::table('outcomes')->join('duty_areas', 'duty_areas.id', '=', 'outcomes.duty_area_id')
            ->where('outcomes.programme_version_id', $e->programme_version_id)
            ->orderBy('duty_areas.number')->orderBy('outcomes.position')
            ->get(['duty_areas.number', 'duty_areas.title as area', 'outcomes.code', 'outcomes.statement', 'outcomes.plain_words']);

        return $rows->groupBy('number')->map(fn (Collection $list, $number) => [
            'number' => (int) $number, 'title' => (string) $list->first()->area,
            'outcomes' => $list->map(fn ($o) => ['code' => $o->code, 'statement' => $o->statement, 'plain_words' => $o->plain_words, 'status' => $status[$o->code] ?? 'not_yet'])->values()->all(),
        ])->values()->all();
    }

    /**
     * Outcome code → shown | practised, for the outcomes the learner has evidence for.
     *
     * @param  Collection<array-key, \stdClass>  $cells  activity id → aggregate row
     * @return array<string, string>
     */
    private function checklistFor(Enrolment $e, Collection $cells): array
    {
        if ($cells->isEmpty()) {
            return [];
        }
        $links = $this->links($e->programme_version_id);
        $out = [];
        foreach ($cells as $activityId => $c) {
            $kind = $links['kinds'][$activityId] ?? null;
            $level = match (true) {
                $kind === Activity::SCENARIO && (bool) $c->job_passed => 'shown',
                $kind === Activity::SCENARIO && (bool) $c->practice_passed => 'practised',
                $kind === Activity::LESSON && (bool) $c->done && ($c->best === null || (int) $c->best >= self::PRACTISED_LESSON_SCORE) => 'practised',
                default => null,
            };
            foreach ($level === null ? [] : ($links['outcomes'][$activityId] ?? []) as $code) {
                if (($out[$code] ?? null) !== 'shown') {
                    $out[$code] = $level;
                }
            }
        }

        return $out;
    }

    /**
     * One row per (enrolment, activity): done, best score, tries, passed in practice / real job.
     *
     * @param  list<string>  $enrolmentIds
     * @return Collection<array-key, Collection<array-key, \stdClass>>
     */
    private function cells(array $enrolmentIds): Collection
    {
        if ($enrolmentIds === []) {
            return collect();
        }

        return Attempt::query()->whereIn('enrolment_id', $enrolmentIds)->whereNotNull('finished_at')
            ->groupBy('enrolment_id', 'activity_id')
            ->selectRaw("enrolment_id, activity_id, bool_or(completed) as done, max(score) as best, count(*) as tries,
                bool_or(mode = 'job' and coalesce((result->>'passed')::boolean, false)) as job_passed,
                bool_or(mode = 'practice' and coalesce((result->>'passed')::boolean, false)) as practice_passed")
            ->toBase()->get()->groupBy('enrolment_id')->map(fn (Collection $rows) => $rows->keyBy('activity_id'));
    }

    /** @var array<string, array{kinds: array<string, string>, outcomes: array<string, list<string>>}> */
    private array $linkCache = [];

    /** @return array{kinds: array<string, string>, outcomes: array<string, list<string>>} */
    private function links(string $versionId): array
    {
        if (! isset($this->linkCache[$versionId])) {
            $activities = Activity::query()->where('programme_version_id', $versionId)->pluck('kind', 'id')->all();
            $outcomes = [];
            foreach (DB::table('activity_outcomes')->whereIn('activity_id', array_keys($activities))->get(['activity_id', 'outcome_code']) as $l) {
                $outcomes[$l->activity_id][] = (string) $l->outcome_code;
            }
            $this->linkCache[$versionId] = ['kinds' => $activities, 'outcomes' => $outcomes];
        }

        return $this->linkCache[$versionId];
    }

    private function outcomeCount(string $versionId): int
    {
        return DB::table('outcomes')->where('programme_version_id', $versionId)->count();
    }
}
