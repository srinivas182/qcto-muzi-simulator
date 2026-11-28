<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Ai\Claude;
use App\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\AssessmentTask;
use App\Models\ProgrammeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * HQ builds each programme version's assessment plan (Sprint 5.1a): the summative tasks for the knowledge,
 * practical and workplace components, which QCTO outcomes each covers, and the question banks of the tests.
 *
 * Same review rules as the studios: a test's question bank is drafted (by an author or the AI), translated,
 * reviewed per language by a subject expert, then published. Changing the English clears every review; changing
 * a translation clears that language's review. Answer keys never reach a learner's device before marking.
 */
final class AssessmentPlans
{
    private const LANGUAGE_NAMES = ['zu' => 'isiZulu', 'xh' => 'isiXhosa', 'af' => 'Afrikaans'];

    public function __construct(
        private readonly Claude $ai,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Every outcome and the summative tasks that cover it, per component.
     *
     * @return array<string, mixed>
     */
    public function plan(ProgrammeVersion $version): array
    {
        $tasks = AssessmentTask::query()->where('programme_version_id', $version->id)->orderBy('position')->get();
        $links = DB::table('assessment_task_outcomes')->whereIn('assessment_task_id', $tasks->pluck('id'))->get()->groupBy('outcome_code');
        $byId = $tasks->keyBy('id');
        $outcomes = DB::table('outcomes')->where('programme_version_id', $version->id)->orderBy('position')->get(['code', 'statement']);

        return [
            'version' => $version->number,
            'tasks' => $tasks->map(fn (AssessmentTask $t) => self::summary($t))->all(),
            'coverage' => $outcomes->map(fn ($o) => [
                'code' => $o->code, 'statement' => $o->statement,
                'components' => collect($links->get($o->code, []))->map(fn ($l) => $byId->get($l->assessment_task_id)?->component)->filter()->unique()->values()->all(),
            ])->all(),
            'uncovered' => $outcomes->filter(fn ($o) => ! $links->has($o->code))->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $outcomes
     */
    public function create(ProgrammeVersion $version, array $input, array $outcomes, User $actor): AssessmentTask
    {
        if (! in_array($input['kind'], AssessmentTask::KINDS[$input['component']] ?? [], true)) {
            throw ValidationException::withMessages(['kind' => 'That kind of task does not belong to this component.']);
        }
        $known = DB::table('outcomes')->where('programme_version_id', $version->id)->whereIn('code', $outcomes)->pluck('code')->all();
        if ($outcomes === [] || count($known) !== count(array_unique($outcomes))) {
            throw ValidationException::withMessages(['outcomes' => 'Link at least one outcome of this programme version.']);
        }
        $activity = null;
        if ($input['kind'] === 'simulator') {
            $id = (string) ($input['activity_id'] ?? '');
            $activity = preg_match('/^[0-9a-f-]{36}$/', $id) === 1 ? Activity::query()->where('programme_version_id', $version->id)->where('kind', Activity::SCENARIO)->where('id', $id)->first() : null;
            if ($activity === null) {
                throw ValidationException::withMessages(['activity_id' => 'Choose a simulator from this programme.']);
            }
        }
        $prefix = $version->programme->prefix;
        $n = AssessmentTask::query()->where('programme_version_id', $version->id)->count() + 1;
        do {
            $code = sprintf('%s-A%02d', $prefix, $n++);
        } while (AssessmentTask::query()->where('programme_version_id', $version->id)->where('code', $code)->exists());

        return DB::transaction(function () use ($version, $input, $outcomes, $actor, $activity, $code): AssessmentTask {
            $task = AssessmentTask::query()->create([
                'programme_version_id' => $version->id, 'code' => $code, 'title' => $input['title'], 'component' => $input['component'], 'kind' => $input['kind'],
                'activity_id' => $activity?->id, 'required' => (bool) ($input['required'] ?? true),
                'position' => (int) AssessmentTask::query()->where('programme_version_id', $version->id)->max('position') + 1,
                'settings' => QuestionBank::settings($input['kind'], [])[0], 'updated_by' => $actor->id,
            ]);
            DB::table('assessment_task_outcomes')->insert(array_map(fn (string $c) => ['assessment_task_id' => $task->id, 'outcome_code' => $c], array_values(array_unique($outcomes))));
            $this->audit->record('assessment_tasks.created', $task, null, ['code' => $code, 'kind' => $task->kind], $actor);

            return $task->refresh();
        });
    }

    /** @return array{questions: list<array<string, mixed>>, settings: array<string, mixed>, i18n: array<string, mixed>, review: array<string, mixed>} */
    public static function draftOf(AssessmentTask $t): array
    {
        $d = $t->draft ?? ($t->content + ['settings' => $t->settings]);

        return ['questions' => (array) ($d['questions'] ?? []), 'settings' => (array) ($d['settings'] ?? $t->settings), 'i18n' => (array) ($d['i18n'] ?? []), 'review' => (array) ($d['review'] ?? [])];
    }

    /** @return list<string> */
    public static function problems(AssessmentTask $t): array
    {
        $d = self::draftOf($t);
        [, $qProblems] = QuestionBank::clean($d['questions'], $t->outcomeCodes());
        [, $sProblems] = QuestionBank::settings($t->kind, $d['settings'], count($d['questions']));

        return $t->kind === 'test' ? [...$qProblems, ...$sProblems] : $sProblems;
    }

    /** @param array<string, mixed> $input */
    public function save(AssessmentTask $t, array $input, User $actor): AssessmentTask
    {
        $d = self::draftOf($t);
        if (array_key_exists('questions', $input) && $t->kind === 'test') {
            [$questions] = QuestionBank::clean($input['questions'], $t->outcomeCodes());
            if ($questions !== $d['questions']) {
                $d['review'] = []; // the English changed: every language needs another look
            }
            $d['questions'] = $questions;
            $d['i18n'] = QuestionBank::cleanI18n($d['i18n'], $questions);
        }
        if (array_key_exists('i18n', $input) && $t->kind === 'test') {
            $new = QuestionBank::cleanI18n($input['i18n'], $d['questions']);
            foreach (QuestionBank::LOCALES as $loc) {
                if (($new[$loc] ?? []) !== ($d['i18n'][$loc] ?? [])) {
                    unset($d['review'][$loc]);
                }
            }
            $d['i18n'] = $new;
        }
        if (array_key_exists('settings', $input)) {
            $d['settings'] = QuestionBank::settings($t->kind, $input['settings'], count($d['questions']))[0];
        }
        $t->fill(array_intersect_key($input, array_flip(['title', 'required'])) + ['draft' => $d, 'draft_updated_at' => now(), 'updated_by' => $actor->id])->save();

        return $t;
    }

    /** Draft questions from the task's outcomes. Only programme content is sent to the AI. */
    public function aiDraft(AssessmentTask $t, int $count, User $actor): AssessmentTask
    {
        if ($t->kind !== 'test') {
            throw ValidationException::withMessages(['ai' => 'Only tests have a question bank.']);
        }
        $outcomes = DB::table('outcomes')->where('programme_version_id', $t->programme_version_id)->whereIn('code', $t->outcomeCodes())->orderBy('position')->get(['code', 'statement']);
        $data = $this->ask(
            'You write summative knowledge questions for South African QCTO occupational qualifications. Learners read English as a second language: '
            .'plain words, one idea per question, realistic workplace situations, no trick questions, no "all of the above". Never invent laws or standards.',
            "Programme: {$t->version->programme->title}\nTest: {$t->title}\nOutcomes:\n".$outcomes->map(fn ($o) => "- {$o->code}: {$o->statement}")->implode("\n")
            ."\n\nReturn {\"questions\": [...]} with {$count} questions spread across the outcomes: mostly single-choice "
            .'{"type":"single","prompt":"...","options":["..."],"answer":0,"explain":"...","outcome":"code","marks":1}, a few multi-choice '
            .'{"type":"multi",...,"answer":[0,2]}, and one or two short answers {"type":"short","prompt":"...","model_answer":"...","outcome":"code","marks":3}.',
        );
        $existing = self::draftOf($t)['questions'];
        $t = $this->save($t, ['questions' => [...$existing, ...array_values(array_filter((array) ($data['questions'] ?? []), 'is_array'))]], $actor);
        $d = self::draftOf($t);
        $d['review']['en'] = ['ai' => true];
        $t->update(['draft' => $d]);
        $this->audit->record('assessment_tasks.ai_drafted', $t, null, ['added' => count($d['questions']) - count($existing)], $actor);

        return $t;
    }

    /** @param list<string> $locales */
    public function aiTranslate(AssessmentTask $t, array $locales, User $actor): AssessmentTask
    {
        $d = self::draftOf($t);
        $source = [];
        foreach ($d['questions'] as $q) {
            $source[$q['id']] = array_intersect_key($q, array_flip($q['type'] === 'short' ? ['prompt'] : QuestionBank::TEXT_FIELDS));
        }
        if ($source === []) {
            throw ValidationException::withMessages(['ai' => 'Write the English questions first.']);
        }
        foreach (array_intersect($locales, QuestionBank::LOCALES) as $loc) {
            $data = $this->ask(
                'You translate test questions for South African learners. Use everyday, natural '.self::LANGUAGE_NAMES[$loc]
                .' as spoken today. Keep technical terms workers use in English (RAM, PC, Wi-Fi). Keep the same meaning, the same number of options, in the same order.',
                'Translate every value into '.self::LANGUAGE_NAMES[$loc].". Keep the keys and structure exactly.\n".json_encode(['questions' => $source], JSON_UNESCAPED_UNICODE),
            );
            $d['i18n'][$loc] = (array) ($data['questions'] ?? []);
            $d['i18n'] = QuestionBank::cleanI18n($d['i18n'], $d['questions']);
            $d['review'][$loc] = ['ai' => true];
        }
        $t->update(['draft' => $d, 'draft_updated_at' => now()]);
        $this->audit->record('assessment_tasks.ai_translated', $t, null, ['locales' => $locales], $actor);

        return $t;
    }

    public function review(AssessmentTask $t, string $locale, bool $reviewed, User $actor): AssessmentTask
    {
        $d = self::draftOf($t);
        if ($locale !== 'en' && ($d['i18n'][$locale] ?? []) === []) {
            throw ValidationException::withMessages(['locale' => 'There is no translation to review yet.']);
        }
        if ($reviewed) {
            $d['review'][$locale] = ['by' => $actor->name, 'at' => now()->toIso8601String()];
        } else {
            unset($d['review'][$locale]);
        }
        $t->update(['draft' => $d]);
        $this->audit->record($reviewed ? 'assessment_tasks.reviewed' : 'assessment_tasks.review_withdrawn', $t, null, ['locale' => $locale], $actor);

        return $t;
    }

    public function publish(AssessmentTask $t, User $actor): AssessmentTask
    {
        $d = self::draftOf($t);
        $problems = self::problems($t);
        if ($t->kind === 'test' && ! isset($d['review']['en']['by'])) {
            $problems[] = 'A subject expert must review the English questions before the test is published.';
        }
        if ($t->kind === 'simulator' && $t->activity?->status !== Activity::PUBLISHED) {
            $problems[] = 'Publish the simulator first.';
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['publish' => $problems]);
        }
        $content = $t->kind === 'test' ? ['questions' => $d['questions'], 'i18n' => $d['i18n'], 'review' => $d['review']] : [];
        $changed = $content !== $t->content || $d['settings'] !== $t->settings;
        $t->fill([
            'content' => $content, 'settings' => $d['settings'], 'draft' => null, 'status' => 'published', 'published_at' => now(),
            'content_version' => $t->status === 'published' && $changed ? $t->content_version + 1 : $t->content_version,
        ])->save();
        $this->audit->record('assessment_tasks.published', $t, null, ['code' => $t->code, 'version' => $t->content_version], $actor);

        return $t;
    }

    /** @return array<string, mixed> */
    public static function summary(AssessmentTask $t): array
    {
        $d = self::draftOf($t);

        return [
            'id' => $t->id, 'code' => $t->code, 'title' => $t->title, 'component' => $t->component, 'kind' => $t->kind, 'required' => $t->required,
            'activity_id' => $t->activity_id, 'status' => $t->status, 'content_version' => $t->content_version, 'has_draft' => $t->draft !== null,
            'questions' => count($d['questions']), 'outcomes' => $t->outcomeCodes(), 'settings' => $d['settings'],
            'review' => array_map(fn ($r) => isset($r['by']) ? 'reviewed' : 'ai', $d['review']),
        ];
    }

    /**
     * When a new programme version is published, its assessment plan carries forward (after the lessons and
     * scenarios have), keeping only outcome links that still exist and pointing simulator tasks at the new copies.
     */
    public static function carryForward(ProgrammeVersion $from, ProgrammeVersion $to): int
    {
        $codes = DB::table('outcomes')->where('programme_version_id', $to->id)->pluck('code')->flip();
        $n = 0;
        foreach (AssessmentTask::query()->where('programme_version_id', $from->id)->where('status', '<>', 'retired')->get() as $old) {
            if (AssessmentTask::query()->where('programme_version_id', $to->id)->where('code', $old->code)->exists()) {
                continue;
            }
            $copy = $old->replicate();
            $copy->programme_version_id = $to->id;
            $copy->activity_id = $old->activity === null ? null : Activity::query()->where('programme_version_id', $to->id)->where('code', $old->activity->code)->value('id');
            $copy->save();
            $links = array_values(array_filter($old->outcomeCodes(), fn ($c) => $codes->has($c)));
            DB::table('assessment_task_outcomes')->insert(array_map(fn ($c) => ['assessment_task_id' => $copy->id, 'outcome_code' => $c], $links));
            $n++;
        }

        return $n;
    }

    /** @return array<string, mixed> */
    private function ask(string $system, string $prompt): array
    {
        try {
            return $this->ai->json($system, $prompt);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['ai' => $e->getMessage()]);
        }
    }
}
