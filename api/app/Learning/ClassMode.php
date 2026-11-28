<?php

declare(strict_types=1);

namespace App\Learning;

use App\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\ClassSession;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Class mode (Sprint 4.7b): the facilitator starts a scenario for the whole class; learners see "Your class is
 * doing this now" and join; the facilitator watches each learner's step live and sees who is stuck.
 *
 * Learners' devices send their moves while they play (the normal offline-safe sync), each attempt tagged with
 * the class. The board is worked out from those attempts with the same scoring as everywhere else. No new
 * personal data is collected.
 */
final class ClassMode
{
    /** No move for this long, or this many mistakes on one step, means "stuck". */
    public const QUIET_SECONDS = 120;

    public const STUCK_MISTAKES = 3;

    public function __construct(private readonly AuditLogger $audit) {}

    public function start(Cohort $cohort, string $activityId, string $mode, User $actor): ClassSession
    {
        $activity = Activity::query()->where('id', $activityId)->where('programme_version_id', $cohort->programme_version_id)
            ->where('kind', Activity::SCENARIO)->where('status', Activity::PUBLISHED)->first();
        if ($activity === null) {
            throw ValidationException::withMessages(['activity_id' => 'Choose a published simulator from this class’s programme.']);
        }
        ClassSession::query()->where('cohort_id', $cohort->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        $session = ClassSession::query()->create(['cohort_id' => $cohort->id, 'activity_id' => $activity->id, 'mode' => $mode, 'started_by' => $actor->id, 'started_at' => now()]);
        $this->audit->record('classes.live_started', $session, null, ['activity' => $activity->code, 'mode' => $mode], $actor);

        return $session;
    }

    public function end(ClassSession $session, User $actor): void
    {
        if ($session->ended_at === null) {
            $session->update(['ended_at' => now()]);
            $this->audit->record('classes.live_ended', $session, null, [], $actor);
        }
    }

    /** @return array<string, mixed> */
    public function board(ClassSession $session): array
    {
        $activity = $session->activity;
        $enrolments = Enrolment::query()->with('learner:id,first_names,surname')->where('cohort_id', $session->cohort_id)
            ->whereIn('status', [Enrolment::ENROLLED, Enrolment::ACTIVE])->get();
        $attempts = Attempt::query()->whereIn('enrolment_id', $enrolments->pluck('id'))->where('activity_id', $activity->id)
            ->where('result->class_session', $session->id)->orderByDesc('updated_at')->get()->unique('enrolment_id')->keyBy('enrolment_id');
        $now = now();

        $rows = $enrolments->map(function (Enrolment $e) use ($attempts, $activity, $session, $now): array {
            /** @var Attempt|null $a */
            $a = $attempts->get($e->id);
            $base = ['id' => $e->id, 'name' => $e->learner->fullName()];
            if ($a === null) {
                return $base + ['joined' => false];
            }
            $events = (array) ($a->result['events'] ?? []);
            $scored = ScenarioContent::score($activity->content ?? [], $events, $session->mode, isset($a->result['variant']) ? (string) $a->result['variant'] : null);
            $steps = array_values($scored['steps']);
            $done = count(array_filter($steps, fn ($s) => $s['done']));
            $current = $steps[$done] ?? null;
            $last = $a->updated_at; // devices send each move straight away in class mode
            $finished = $a->finished_at !== null;

            return $base + [
                'joined' => true, 'step' => min($done + 1, count($steps)), 'steps' => count($steps), 'finished' => $finished,
                'score' => $finished ? $a->score : null, 'passed' => $finished ? (bool) ($a->result['passed'] ?? false) : null,
                'mistakes' => array_sum(array_column($steps, 'mistakes')), 'help' => count(array_filter($events, fn ($ev) => in_array($ev['action'] ?? '', ['hint', 'coach'], true))),
                'last_move_at' => $last->toIso8601String(),
                'stuck' => ! $finished && ($last->lt($now->copy()->subSeconds(self::QUIET_SECONDS)) || ($current['mistakes'] ?? 0) >= self::STUCK_MISTAKES),
            ];
        })->sortBy([['stuck', 'desc'], ['joined', 'desc'], ['name', 'asc']])->values();

        return [
            'session' => self::present($session),
            'learners' => $rows->all(),
            'summary' => [
                'learners' => $rows->count(), 'joined' => $rows->where('joined', true)->count(),
                'finished' => $rows->where('finished', true)->count(), 'stuck' => $rows->where('stuck', true)->count(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function present(ClassSession $s): array
    {
        return [
            'id' => $s->id, 'cohort_id' => $s->cohort_id, 'activity_id' => $s->activity_id, 'title' => $s->activity->title, 'code' => $s->activity->code,
            'mode' => $s->mode, 'started_at' => $s->started_at->toIso8601String(), 'live' => $s->isLive(),
        ];
    }
}
