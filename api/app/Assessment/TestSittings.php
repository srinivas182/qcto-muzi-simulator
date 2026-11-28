<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Audit\AuditLogger;
use App\Models\AssessmentTask;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\TestSitting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Learners sit knowledge tests (Sprint 5.1b).
 *
 * - Starting a sitting draws the task's number of questions at random from its bank and shuffles each question's
 *   options. The paper is stored, so a resumed sitting shows the same questions in the same order.
 * - The device receives questions and options only. Answer keys, explanations and model answers stay on the
 *   server until marking, so sharing a phone screen gives nothing away beyond that one paper.
 * - The deadline is the server's. Answers saved on a phone that lost signal are accepted up to 15 minutes after
 *   the deadline and flagged "late"; after that the sitting closes with the answers the server already has.
 * - Choice questions are marked straight away. Short answers wait for an assessor; then the result is final.
 */
final class TestSittings
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Start (or resume) a sitting and return the learner's paper. */
    public function start(Tenant $tenant, Enrolment $e, AssessmentTask $task, string $locale, ?string $device, bool $cameraConsent, User $actor): TestSitting
    {
        $this->assertAvailable($e, $task);
        $camera = CameraChecks::required($tenant, $task);
        $open = TestSitting::query()->where('enrolment_id', $e->id)->where('assessment_task_id', $task->id)->where('status', 'open')->latest('started_at')->first();
        if ($open !== null) {
            if ($this->closeIfExpired($open)->status === 'open') {
                return $open;
            }
        }
        $used = TestSitting::query()->where('enrolment_id', $e->id)->where('assessment_task_id', $task->id)->count();
        $passed = TestSitting::query()->where('enrolment_id', $e->id)->where('assessment_task_id', $task->id)->where('result', 'competent')->exists();
        if ($passed) {
            throw ValidationException::withMessages(['test' => 'You have already passed this test.']);
        }
        if ($used >= (int) ($task->settings['attempts'] ?? 1)) {
            throw ValidationException::withMessages(['test' => 'You have used all your attempts. Your assessor will contact you.']);
        }
        if ($camera && ! $cameraConsent) {
            throw ValidationException::withMessages(['camera' => 'This test uses a camera check. Agree to it to start, or ask your provider for a supervised sitting.']);
        }
        $questions = collect((array) ($task->content['questions'] ?? []))->shuffle()->take((int) ($task->settings['pick'] ?? 10));
        $paper = $questions->map(fn (array $q) => ['id' => $q['id'], 'options' => isset($q['options']) ? collect(array_keys($q['options']))->shuffle()->values()->all() : []])->values()->all();
        $sitting = TestSitting::query()->create([
            'enrolment_id' => $e->id, 'assessment_task_id' => $task->id, 'content_version' => $task->content_version, 'attempt' => $used + 1,
            'locale' => in_array($locale, QuestionBank::LOCALES, true) && isset($task->content['review'][$locale]['by']) ? $locale : 'en',
            'started_at' => now(), 'deadline_at' => now()->addMinutes((int) ($task->settings['minutes'] ?? 30)), 'paper' => $paper, 'device' => $device === null ? null : mb_substr($device, 0, 40),
            'camera' => $camera, 'camera_consent_at' => $camera ? now() : null,
        ]);
        $this->audit->record('tests.started', $sitting, null, ['task' => $task->code, 'attempt' => $sitting->attempt, 'camera' => $camera], $actor);

        return $sitting;
    }

    /**
     * What the learner's device may see: questions and shuffled options in their language. No answers.
     *
     * @return array<string, mixed>
     */
    public static function paperFor(TestSitting $s): array
    {
        $task = $s->task;
        $bank = collect((array) ($task->content['questions'] ?? []))->keyBy('id');
        $tr = (array) ($task->content['i18n'][$s->locale] ?? []);

        return [
            'id' => $s->id, 'task' => ['id' => $task->id, 'code' => $task->code, 'title' => $task->title, 'pass_mark' => (int) ($task->settings['pass_mark'] ?? 70)],
            'attempt' => $s->attempt, 'locale' => $s->locale, 'status' => $s->status, 'started_at' => $s->started_at->toIso8601String(),
            'deadline_at' => $s->deadline_at->toIso8601String(), 'seconds_left' => max(0, (int) now()->diffInSeconds($s->deadline_at, false)),
            'answers' => $s->answers, 'camera' => $s->camera, 'photos_max' => $s->camera ? CameraChecks::MAX_PHOTOS : 0,
            'questions' => array_map(function (array $p) use ($bank, $tr): array {
                $q = (array) $bank->get($p['id']);
                $t = (array) ($tr[$p['id']] ?? []);
                $options = (array) ($t['options'] ?? $q['options'] ?? []);

                return ['id' => $p['id'], 'type' => $q['type'], 'prompt' => (string) ($t['prompt'] ?? $q['prompt']), 'marks' => (int) $q['marks'],
                    'options' => array_map(fn (int $i) => (string) ($options[$i] ?? ''), $p['options'])];
            }, $s->paper),
        ];
    }

    /** Save answers (progress or final). Late answers within the grace period are kept and flagged. */
    public function save(TestSitting $s, mixed $answers, bool $submit, User $actor): TestSitting
    {
        $s = $this->closeIfExpired($s);
        if ($s->status !== 'open') {
            throw ValidationException::withMessages(['test' => 'This test is closed.']);
        }
        $late = now()->gt($s->deadline_at);
        $clean = [];
        $paper = collect($s->paper)->keyBy('id');
        $bank = collect((array) ($s->task->content['questions'] ?? []))->keyBy('id');
        foreach (is_array($answers) ? $answers : [] as $qid => $a) {
            $p = $paper->get($qid);
            $q = $bank->get($qid);
            if ($p === null || ! is_array($q)) {
                continue;
            }
            $n = count($p['options']);
            $clean[$qid] = match ($q['type']) {
                'single' => is_int($a) && $a >= 0 && $a < $n ? $a : null,
                'multi' => array_values(array_unique(array_filter(array_map('intval', (array) $a), fn (int $x) => $x >= 0 && $x < $n))),
                default => mb_substr(trim((string) $a), 0, 3000),
            };
        }
        $s->fill(['answers' => array_filter($clean, fn ($v) => $v !== null && $v !== '' && $v !== []) + array_diff_key($s->answers, $clean), 'late' => $s->late || $late])->save();

        return $submit || $late ? $this->finish($s, $actor) : $s;
    }

    /** Close a sitting whose deadline and grace period have passed, with the answers the server already has. */
    public function closeIfExpired(TestSitting $s): TestSitting
    {
        if ($s->status === 'open' && now()->gt($s->deadline_at->copy()->addMinutes(TestSitting::GRACE_MINUTES))) {
            return $this->finish($s, null);
        }

        return $s;
    }

    /**
     * Assessor marks for the short answers; the sitting's result is then final.
     *
     * @param  array<string, mixed>  $marks
     */
    public function mark(TestSitting $s, array $marks, User $assessor): TestSitting
    {
        if ($s->status !== 'submitted') {
            throw ValidationException::withMessages(['marks' => 'Only submitted tests with short answers wait for marking.']);
        }
        $bank = collect((array) ($s->task->content['questions'] ?? []))->keyBy('id');
        $given = $s->marks;
        foreach ($s->paper as $p) {
            $q = (array) $bank->get($p['id']);
            if (($q['type'] ?? null) === 'short') {
                if (! array_key_exists($p['id'], $marks)) {
                    throw ValidationException::withMessages(['marks' => 'Mark every short answer.']);
                }
                $given[$p['id']] = max(0, min((int) $q['marks'], (int) $marks[$p['id']]));
            }
        }
        $s->fill(['marks' => $given, 'marked_by' => $assessor->id, 'marked_at' => now()])->save();
        $this->conclude($s);
        $this->audit->record('tests.marked', $s, null, ['score' => $s->score, 'result' => $s->result], $assessor);

        return $s;
    }

    private function finish(TestSitting $s, ?User $actor): TestSitting
    {
        DB::transaction(function () use ($s): void {
            $bank = collect((array) ($s->task->content['questions'] ?? []))->keyBy('id');
            $marks = [];
            $short = false;
            foreach ($s->paper as $p) {
                $q = (array) $bank->get($p['id']);
                $a = $s->answers[$p['id']] ?? null;
                // Shown option index → original option index.
                $orig = fn (int $shown) => $p['options'][$shown] ?? -1;
                $marks[$p['id']] = match ($q['type'] ?? null) {
                    'single' => is_int($a) && $orig($a) === $q['answer'] ? (int) $q['marks'] : 0,
                    'multi' => is_array($a) && self::sorted(array_map($orig, $a)) === self::sorted((array) $q['answer']) ? (int) $q['marks'] : 0,
                    default => null,
                };
                if (($q['type'] ?? null) === 'short') {
                    $short = $short || ($a !== null && $a !== '');
                    $marks[$p['id']] = ($a === null || $a === '') ? 0 : null; // blank short answers score 0 without marking
                }
            }
            $s->fill(['submitted_at' => now(), 'marks' => array_filter($marks, fn ($m) => $m !== null), 'status' => $short ? 'submitted' : 'marked'])->save();
            if (! $short) {
                $this->conclude($s);
            }
        });
        $this->audit->record('tests.submitted', $s, null, ['status' => $s->status, 'late' => $s->late], $actor);

        return $s;
    }

    private function conclude(TestSitting $s): void
    {
        $bank = collect((array) ($s->task->content['questions'] ?? []))->keyBy('id');
        $total = array_sum(array_map(fn (array $p) => (int) ($bank->get($p['id'])['marks'] ?? 0), $s->paper));
        $score = $total > 0 ? (int) round(100 * array_sum($s->marks) / $total) : 0;
        $s->fill(['status' => 'marked', 'score' => $score, 'result' => $score >= (int) ($s->task->settings['pass_mark'] ?? 70) ? 'competent' : 'not_yet'])->save();
        CameraChecks::scheduleDeletion($s); // photos (if any) are kept 90 days after the result is final
    }

    /**
     * What the learner sees after marking: score, result, and the outcomes to revise (never the answers).
     *
     * @return array<string, mixed>
     */
    public static function resultFor(TestSitting $s): array
    {
        $bank = collect((array) ($s->task->content['questions'] ?? []))->keyBy('id');
        $revise = collect($s->paper)->filter(fn (array $p) => ($s->marks[$p['id']] ?? 0) < (int) ($bank->get($p['id'])['marks'] ?? 0))
            ->map(fn (array $p) => $bank->get($p['id'])['outcome'] ?? null)->filter()->unique()->values();
        $statements = DB::table('outcomes')->where('programme_version_id', $s->task->programme_version_id)->whereIn('code', $revise)->pluck('statement', 'code');

        return [
            'id' => $s->id, 'status' => $s->status, 'attempt' => $s->attempt, 'score' => $s->score, 'result' => $s->result, 'late' => $s->late,
            'submitted_at' => $s->submitted_at?->toIso8601String(),
            'revise' => $s->status === 'marked' ? $revise->map(fn ($c) => ['code' => $c, 'statement' => $statements[$c] ?? $c])->all() : [],
        ];
    }

    /**
     * @param  array<int, int>  $xs
     * @return list<int>
     */
    private static function sorted(array $xs): array
    {
        $xs = array_values($xs);
        sort($xs);

        return $xs;
    }

    private function assertAvailable(Enrolment $e, AssessmentTask $task): void
    {
        if ($task->kind !== 'test' || $task->status !== 'published' || $task->programme_version_id !== $e->programme_version_id
            || ! in_array($e->status, [Enrolment::ENROLLED, Enrolment::ACTIVE], true)) {
            throw ValidationException::withMessages(['test' => 'This test is not available to you.']);
        }
    }
}
