<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Learning\ScenarioContent;
use App\Models\Activity;
use App\Models\AssessmentDecision;
use App\Models\AssessmentTask;
use App\Models\Attempt;
use App\Models\Enrolment;
use App\Models\TestSitting;
use Illuminate\Support\Collection;

/**
 * What an assessor works from (Sprint 5.2): one queue of written answers to mark, real-job simulator runs to
 * judge and observations to do, and a step-by-step replay of a run with its evidence fingerprint.
 * A few set-based queries per request, so the queue stays fast for a 200-learner provider.
 */
final class AssessorWorkspace
{
    /**
     * @return array{mark: list<array<string, mixed>>, runs: list<array<string, mixed>>, observe: list<array<string, mixed>>, done: list<array<string, mixed>>}
     */
    public function queue(?string $cohortId): array
    {
        $enrolments = Enrolment::query()->with(['learner', 'cohort'])->whereIn('status', ['enrolled', 'active'])
            ->when($cohortId !== null, fn ($q) => $q->where('cohort_id', $cohortId))->get()->keyBy('id');
        $ids = $enrolments->keys()->all();
        $tasks = AssessmentTask::query()->whereIn('programme_version_id', $enrolments->pluck('programme_version_id')->unique()->all())
            ->where('status', 'published')->orderBy('position')->get();
        $decisions = AssessmentDecision::query()->whereIn('enrolment_id', $ids)->get()->groupBy(fn (AssessmentDecision $d) => $d->enrolment_id.'|'.$d->assessment_task_id);
        $who = fn (Enrolment $e): array => ['enrolment_id' => $e->id, 'learner' => $e->learner->fullName(), 'cohort' => $e->cohort->name];
        $taskRef = fn (AssessmentTask $t): array => ['id' => $t->id, 'code' => $t->code, 'title' => $t->title];

        $mark = TestSitting::query()->whereIn('enrolment_id', $ids)->where('status', 'submitted')->orderBy('submitted_at')->limit(200)->get()
            ->map(fn (TestSitting $s) => $who($enrolments[$s->enrolment_id]) + ['kind' => 'mark', 'id' => $s->id, 'task' => $taskRef($s->task), 'at' => $s->submitted_at?->toIso8601String(), 'late' => $s->late, 'camera' => $s->camera])
            ->values()->all();

        $open = function (Enrolment $e, AssessmentTask $t) use ($decisions): ?array {
            $mine = $decisions->get($e->id.'|'.$t->id, collect());
            if ($e->programme_version_id !== $t->programme_version_id || $mine->contains('result', 'competent') || $mine->count() >= Decisions::roundsAllowed($t)) {
                return null;
            }

            return ['round' => $mine->count() + 1, 'rounds_allowed' => Decisions::roundsAllowed($t)];
        };

        $sims = $tasks->where('kind', 'simulator')->whereNotNull('activity_id');
        $judged = AssessmentDecision::query()->whereIn('enrolment_id', $ids)->whereNotNull('attempt_id')->pluck('attempt_id')->flip();
        $latest = Attempt::query()->whereIn('enrolment_id', $ids)->whereIn('activity_id', $sims->pluck('activity_id')->all())->where('mode', 'job')
            ->whereNotNull('finished_at')->orderBy('finished_at')->get(['id', 'enrolment_id', 'activity_id', 'finished_at', 'score', 'completed', 'result'])
            ->reject(fn (Attempt $a) => $judged->has($a->id))->keyBy(fn (Attempt $a) => $a->enrolment_id.'|'.$a->activity_id); // the newest unjudged run wins
        $runs = [];
        foreach ($sims as $t) {
            foreach ($enrolments as $e) {
                $a = $latest->get($e->id.'|'.$t->activity_id);
                if ($a !== null && ($o = $open($e, $t)) !== null) {
                    $runs[] = $who($e) + $o + ['kind' => 'run', 'id' => $a->id, 'task' => $taskRef($t), 'at' => $a->finished_at?->toIso8601String(),
                        'score' => $a->score, 'passed' => (bool) ($a->result['passed'] ?? false), 'critical' => (bool) ($a->result['critical'] ?? false)];
                }
            }
        }
        usort($runs, fn ($x, $y) => strcmp((string) $x['at'], (string) $y['at']));

        // An observation is "ready" once the learner is competent on the simulator tasks of the same component.
        $observe = [];
        foreach ($tasks->where('kind', 'observation') as $t) {
            $before = $sims->where('programme_version_id', $t->programme_version_id)->where('component', $t->component);
            foreach ($enrolments as $e) {
                if (($o = $open($e, $t)) === null) {
                    continue;
                }
                $ready = $before->every(fn (AssessmentTask $s) => $decisions->get($e->id.'|'.$s->id, collect())->contains('result', 'competent'));
                $observe[] = $who($e) + $o + ['kind' => 'observe', 'id' => $e->id.'|'.$t->id, 'task' => $taskRef($t), 'ready' => $ready];
            }
        }
        usort($observe, fn ($x, $y) => [! $x['ready'], $x['learner']] <=> [! $y['ready'], $y['learner']]);

        $done = AssessmentDecision::query()->with(['task', 'assessor'])->whereIn('enrolment_id', $ids)->latest()->limit(50)->get()
            ->map(fn (AssessmentDecision $d) => $who($enrolments[$d->enrolment_id]) + ['kind' => 'decision', 'id' => $d->id, 'task' => $taskRef($d->task),
                'result' => $d->result, 'round' => $d->round, 'assessor' => $d->assessor->name, 'at' => $d->created_at->toIso8601String()])
            ->values()->all();

        return ['mark' => $mark, 'runs' => $runs, 'observe' => array_slice($observe, 0, 300), 'done' => $done];
    }

    /**
     * A real-job run, step by step: each move in order with whether it was right, hints, mistakes and timing.
     *
     * @return array<string, mixed>
     */
    public function replay(Attempt $a): array
    {
        $e = Enrolment::query()->with('learner')->findOrFail($a->enrolment_id);
        $sim = Activity::query()->findOrFail($a->activity_id);
        $content = $sim->content ?? [];
        $steps = ScenarioContent::stepsFor($content, isset($a->result['variant']) ? (string) $a->result['variant'] : null);
        $events = collect((array) ($a->result['events'] ?? []))->filter(fn ($ev) => is_array($ev))->groupBy(fn (array $ev) => (string) ($ev['step'] ?? ''));
        $per = (array) ($a->result['steps'] ?? []);
        $task = AssessmentTask::query()->where('programme_version_id', $e->programme_version_id)->where('activity_id', $a->activity_id)->where('kind', 'simulator')->where('status', 'published')->first();
        $decision = AssessmentDecision::query()->with('assessor')->where('attempt_id', $a->id)->first();
        $hash = Decisions::hashOf($a);

        return [
            'id' => $a->id, 'enrolment_id' => $e->id, 'learner' => $e->learner->fullName(),
            'simulator' => ['id' => $sim->id, 'code' => $sim->code, 'title' => $sim->title, 'version_now' => $sim->content_version],
            'version_played' => $a->content_version, 'mode' => $a->mode, 'device' => $a->device,
            'started_at' => $a->started_at->toIso8601String(), 'finished_at' => $a->finished_at?->toIso8601String(), 'duration_seconds' => $a->duration_seconds,
            'score' => $a->score, 'pass_mark' => (int) ($content['pass_mark'] ?? 70), 'passed' => (bool) ($a->result['passed'] ?? false), 'critical' => (bool) ($a->result['critical'] ?? false),
            'variant' => $a->result['variant'] ?? null,
            'steps' => array_map(function (array $st) use ($events, $per): array {
                $moves = $events->get($st['id'], collect())->map(fn (array $ev): array => [
                    'action' => (string) ($ev['action'] ?? ''), 'value' => $this->describe($st, $ev), 't' => (int) ($ev['t'] ?? 0),
                    'right' => in_array($ev['action'] ?? '', ['tap', 'choose', 'place'], true) ? ScenarioContent::isRight($st, (string) $ev['action'], (string) ($ev['value'] ?? '')) : null,
                ])->values()->all();

                return ['id' => $st['id'], 'prompt' => $st['prompt'], 'critical' => (bool) ($st['critical'] ?? false), 'outcome' => $st['outcome'] ?? null,
                    'lesson' => $st['lesson']['code'] ?? null, 'done' => (bool) ($per[$st['id']]['done'] ?? false),
                    'mistakes' => (int) ($per[$st['id']]['mistakes'] ?? 0), 'hints' => (int) ($per[$st['id']]['hints'] ?? 0), 'moves' => $moves];
            }, $steps),
            'hash' => $hash,
            'task' => $task === null ? null : $this->taskFor($e, $task),
            'decision' => $decision === null ? null : $this->decisionOut($decision) + ['hash_matches' => $decision->evidence_hash === $hash],
        ];
    }

    /**
     * A learner's standing on one practical task: the checklist, the decisions so far and what can be linked.
     *
     * @return array<string, mixed>
     */
    public function taskFor(Enrolment $e, AssessmentTask $t): array
    {
        $history = AssessmentDecision::query()->with('assessor')->where('enrolment_id', $e->id)->where('assessment_task_id', $t->id)->orderBy('round')->get();
        $steps = [];
        if ($t->activity_id !== null) {
            $steps = array_map(fn ($st) => ['id' => (string) $st['id'], 'prompt' => (string) $st['prompt']], (array) (Activity::query()->find($t->activity_id)?->content['steps'] ?? []));
        }

        return [
            'id' => $t->id, 'code' => $t->code, 'title' => $t->title, 'kind' => $t->kind, 'items' => array_values((array) ($t->settings['items'] ?? [])),
            'rounds_allowed' => Decisions::roundsAllowed($t), 'rounds_used' => $history->count(), 'competent' => $history->contains('result', 'competent'),
            'history' => $history->map(fn (AssessmentDecision $d) => $this->decisionOut($d))->all(),
            'link_options' => [
                'steps' => $steps,
                'lessons' => Activity::query()->where('programme_version_id', $t->programme_version_id)->where('kind', Activity::LESSON)->orderBy('position')->get(['code', 'title'])
                    ->map(fn (Activity $l) => ['code' => $l->code, 'title' => $l->title])->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function decisionOut(AssessmentDecision $d): array
    {
        return ['id' => $d->id, 'round' => $d->round, 'result' => $d->result, 'feedback' => $d->feedback, 'links' => $d->links, 'observation' => $d->observation,
            'attempt_id' => $d->attempt_id, 'assessor' => $d->assessor->name, 'at' => $d->created_at->toIso8601String(), 'hash' => $d->evidence_hash];
    }

    /**
     * @param  array<string, mixed>  $st
     * @param  array<string, mixed>  $ev
     */
    private function describe(array $st, array $ev): ?string
    {
        $v = isset($ev['value']) ? (string) $ev['value'] : null;
        if (($ev['action'] ?? '') === 'choose' && $v !== null && ctype_digit($v)) {
            return (string) ($st['options'][(int) $v] ?? $v);
        }

        return $v === null ? null : mb_substr($v, 0, 120);
    }

    /** @param Collection<int, AssessmentDecision> $decisions */
    public static function resultOf(Collection $decisions): ?string
    {
        return $decisions->contains('result', 'competent') ? 'competent' : ($decisions->isNotEmpty() ? 'not_yet' : null);
    }
}
