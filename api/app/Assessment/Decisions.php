<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\AssessmentDecision;
use App\Models\AssessmentTask;
use App\Models\Attempt;
use App\Models\Enrolment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assessor decisions on practical tasks (Sprint 5.2): a real-job simulator run, or an observation checklist.
 *
 * Rules: one decision per opportunity; at most `attempts` opportunities (default 3); no decision after
 * competent; a not-yet-competent result needs feedback; the observation is competent only when every item is
 * met; the evidence is fingerprinted (sha256) so a later change to it shows up.
 */
final class Decisions
{
    public const DEFAULT_ROUNDS = 3;

    public const MAX_LINKS = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /** How many opportunities a learner gets on a practical task. */
    public static function roundsAllowed(AssessmentTask $task): int
    {
        return max(1, min(5, (int) ($task->settings['attempts'] ?? self::DEFAULT_ROUNDS)));
    }

    /** The fingerprint of a simulator run as it stands: the moves, the version played and when it finished. */
    public static function hashOf(Attempt $a): string
    {
        return hash('sha256', (string) json_encode([
            'attempt' => $a->id, 'activity' => $a->activity_id, 'version' => $a->content_version, 'mode' => $a->mode,
            'events' => $a->result['events'] ?? [], 'variant' => $a->result['variant'] ?? null,
            'finished_at' => $a->finished_at?->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Record a decision.
     *
     * @param  array{result: string, feedback?: string|null, links?: list<array<string, mixed>>, attempt_id?: string|null, observation?: list<array<string, mixed>>|null, client_id?: string|null, declared_at?: string|null}  $input
     */
    public function decide(Enrolment $e, AssessmentTask $task, array $input, User $assessor): AssessmentDecision
    {
        if ($task->programme_version_id !== $e->programme_version_id || $task->status !== 'published' || ! in_array($task->kind, ['simulator', 'observation'], true)) {
            throw ValidationException::withMessages(['task' => 'This task is not a practical task of this learner’s programme.']);
        }

        $client = isset($input['client_id']) && preg_match('/^[0-9a-f-]{36}$/', (string) $input['client_id']) === 1 ? (string) $input['client_id'] : null;
        if ($client !== null && ($same = AssessmentDecision::query()->where('client_id', $client)->first()) !== null) {
            if ($same->enrolment_id !== $e->id || $same->assessment_task_id !== $task->id) {
                throw ValidationException::withMessages(['client_id' => 'This id belongs to another decision.']);
            }

            return $same; // sent again from a device that was offline: already recorded
        }

        return DB::transaction(function () use ($e, $task, $input, $assessor, $client): AssessmentDecision {
            $earlier = AssessmentDecision::query()->where('enrolment_id', $e->id)->where('assessment_task_id', $task->id)->lockForUpdate()->get();
            if ($earlier->contains('result', 'competent')) {
                throw ValidationException::withMessages(['result' => 'This learner is already competent on this task.']);
            }
            if ($earlier->count() >= self::roundsAllowed($task)) {
                throw ValidationException::withMessages(['result' => 'All opportunities on this task are used. Agree a development plan with the learner before a new assessment.']);
            }
            $result = $input['result'];
            $feedback = trim((string) ($input['feedback'] ?? ''));
            if ($result === 'not_yet' && $feedback === '') {
                throw ValidationException::withMessages(['feedback' => 'Say what the learner must do to be competent next time.']);
            }
            [$attempt, $observation, $hash] = $task->kind === 'simulator'
                ? $this->runEvidence($e, $task, $input['attempt_id'] ?? null)
                : $this->observationEvidence($task, $input['observation'] ?? null, $result);

            $decision = AssessmentDecision::query()->create([
                'enrolment_id' => $e->id, 'assessment_task_id' => $task->id, 'round' => $earlier->count() + 1, 'result' => $result,
                'attempt_id' => $attempt?->id, 'observation' => $observation, 'feedback' => $feedback === '' ? null : mb_substr($feedback, 0, 2000),
                'links' => $this->links($task, $input['links'] ?? []), 'evidence_hash' => $hash,
                'assessor_id' => $assessor->id, 'declared_at' => isset($input['declared_at']) ? min(now(), Carbon::parse((string) $input['declared_at'])) : now(), 'client_id' => $client,
            ]);
            $this->audit->record('assessments.decided', $decision, null, ['task' => $task->code, 'result' => $result, 'round' => $decision->round, 'hash' => $hash], $assessor);

            return $decision;
        });
    }

    /** @return array{0: Attempt, 1: null, 2: string} */
    private function runEvidence(Enrolment $e, AssessmentTask $task, ?string $attemptId): array
    {
        $attempt = $attemptId === null || preg_match('/^[0-9a-f-]{36}$/', $attemptId) !== 1 ? null : Attempt::query()
            ->whereKey($attemptId)->where('enrolment_id', $e->id)->where('activity_id', $task->activity_id)->where('mode', 'job')->whereNotNull('finished_at')->first();
        if ($attempt === null) {
            throw ValidationException::withMessages(['attempt_id' => 'Choose a finished real-job run of this simulator by this learner.']);
        }
        if (AssessmentDecision::query()->where('attempt_id', $attempt->id)->exists()) {
            throw ValidationException::withMessages(['attempt_id' => 'This run has already been judged. The learner needs a new run.']);
        }

        return [$attempt, null, self::hashOf($attempt)];
    }

    /**
     * @return array{0: null, 1: list<array{item: string, met: bool, note: string|null}>, 2: string}
     */
    private function observationEvidence(AssessmentTask $task, mixed $rows, string $result): array
    {
        $items = array_values((array) ($task->settings['items'] ?? []));
        $rows = is_array($rows) ? array_values($rows) : [];
        if (count($rows) !== count($items)) {
            throw ValidationException::withMessages(['observation' => 'Tick met or not met for every item on the checklist.']);
        }
        $out = [];
        foreach ($items as $i => $item) {
            $row = is_array($rows[$i]) ? $rows[$i] : [];
            $note = trim((string) ($row['note'] ?? ''));
            $out[] = ['item' => (string) $item, 'met' => (bool) ($row['met'] ?? false), 'note' => $note === '' ? null : mb_substr($note, 0, 300)];
        }
        if ($result === 'competent' && in_array(false, array_column($out, 'met'), true)) {
            throw ValidationException::withMessages(['result' => 'Competent needs every checklist item met.']);
        }

        return [null, $out, hash('sha256', (string) json_encode($out, JSON_UNESCAPED_UNICODE))];
    }

    /**
     * Feedback links: a step of this simulator, or a lesson of the programme, so the learner can go straight there.
     *
     * @param  list<array<string, mixed>>  $links
     * @return list<array{type: string, ref: string, label: string}>
     */
    private function links(AssessmentTask $task, array $links): array
    {
        $steps = [];
        if ($task->activity_id !== null) {
            $sim = Activity::query()->find($task->activity_id);
            foreach ((array) ($sim?->content['steps'] ?? []) as $st) {
                $steps[(string) $st['id']] = (string) $st['prompt'];
            }
        }
        $lessons = Activity::query()->where('programme_version_id', $task->programme_version_id)->where('kind', Activity::LESSON)->pluck('title', 'code')->all();
        $out = [];
        foreach (array_slice($links, 0, self::MAX_LINKS) as $l) {
            $type = (string) ($l['type'] ?? '');
            $ref = (string) ($l['ref'] ?? '');
            if ($type === 'step' && isset($steps[$ref])) {
                $out[] = ['type' => 'step', 'ref' => $ref, 'label' => mb_substr($steps[$ref], 0, 120)];
            } elseif ($type === 'lesson' && isset($lessons[$ref])) {
                $out[] = ['type' => 'lesson', 'ref' => $ref, 'label' => (string) $lessons[$ref]];
            }
        }

        return array_values(array_unique($out, SORT_REGULAR));
    }
}
