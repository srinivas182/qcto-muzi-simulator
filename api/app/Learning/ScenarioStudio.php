<?php

declare(strict_types=1);

namespace App\Learning;

use App\Ai\Claude;
use App\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Scenario Studio (HQ): build a simulator scenario with forms on a ready-made scene, preview it, translate it,
 * have each language reviewed, and publish. Same review rules as Course Studio: an English change clears every
 * review, a translation change clears that language's review, and learners see only reviewed languages.
 */
final class ScenarioStudio
{
    private const LANGUAGE_NAMES = ['zu' => 'isiZulu', 'xh' => 'isiXhosa', 'af' => 'Afrikaans'];

    public function __construct(
        private readonly Claude $ai,
        private readonly ActivityCatalogue $catalogue,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{scenario: array<string, mixed>, i18n: array<string, mixed>, review: array<string, mixed>} */
    public static function draftOf(Activity $a): array
    {
        $d = $a->draft ?? $a->content ?? [];
        $scenario = array_intersect_key((array) $d, array_flip(['scene', 'pass_mark', 'job', 'variants', 'steps']));

        return [
            'scenario' => $scenario + ['scene' => 'pc-bench', 'pass_mark' => 70, 'job' => null, 'variants' => [], 'steps' => []],
            'i18n' => (array) ($d['i18n'] ?? []), 'review' => (array) ($d['review'] ?? []),
        ];
    }

    /**
     * Keep only translations of text that exists, in the same shape as the English.
     *
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    public static function cleanI18n(mixed $i18n, array $scenario): array
    {
        $steps = [];
        foreach ((array) ($scenario['steps'] ?? []) as $st) {
            $steps[$st['id']] = $st;
        }
        $txt = fn ($v, int $max) => mb_substr(trim((string) $v), 0, $max);
        $list = fn ($v, int $n, int $max) => array_values(array_map(fn ($x) => mb_substr(trim((string) $x), 0, $max), array_slice((array) $v, 0, $n)));
        $out = [];
        foreach (LessonContent::LOCALES as $loc) {
            $tr = (array) (((array) $i18n)[$loc] ?? []);
            if (isset($tr['job']['customer'], $scenario['job']) && trim((string) $tr['job']['customer']) !== '') {
                $out[$loc]['job']['customer'] = $txt($tr['job']['customer'], 300);
            }
            foreach ((array) ($tr['steps'] ?? []) as $id => $f) {
                $st = $steps[$id] ?? null;
                if ($st === null || ! is_array($f)) {
                    continue;
                }
                $row = [];
                isset($f['prompt']) && $row['prompt'] = $txt($f['prompt'], 300);
                isset($f['why']) && $row['why'] = $txt($f['why'], 400);
                isset($f['hints']) && $row['hints'] = $list($f['hints'], count($st['hints']), 200);
                isset($f['options'], $st['options']) && $row['options'] = $list($f['options'], count($st['options']), 160);
                isset($f['wrong']) && $row['wrong'] = $list($f['wrong'], count($st['wrong']), 300);
                $row = array_filter($row, fn ($v) => $v !== '' && $v !== []);
                if ($row !== []) {
                    $out[$loc]['steps'][$id] = $row;
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $input */
    public function save(Activity $a, array $input, User $actor): Activity
    {
        $draft = self::draftOf($a);
        if (array_key_exists('scenario', $input)) {
            [$scenario] = ScenarioContent::clean($input['scenario']);
            if ($this->english($scenario) !== $this->english($draft['scenario'])) {
                $draft['review'] = []; // the English words changed: every language needs another look
            } elseif ($scenario !== $draft['scenario']) {
                unset($draft['review']['en']); // the steps or answers changed: the expert checks the scenario again
            }
            $draft['scenario'] = $scenario;
            $draft['i18n'] = self::cleanI18n($draft['i18n'], $scenario);
        }
        if (array_key_exists('i18n', $input)) {
            $new = self::cleanI18n($input['i18n'], $draft['scenario']);
            foreach (LessonContent::LOCALES as $loc) {
                if (($new[$loc] ?? []) !== ($draft['i18n'][$loc] ?? [])) {
                    unset($draft['review'][$loc]);
                }
            }
            $draft['i18n'] = $new;
        }
        $meta = array_intersect_key($input, array_flip(['title', 'summary', 'minutes', 'weight', 'required']));
        $a->fill($meta + ['draft' => $draft['scenario'] + ['i18n' => $draft['i18n'], 'review' => $draft['review']], 'draft_updated_at' => now(), 'updated_by' => $actor->id])->save();

        return $a;
    }

    /**
     * Draft translations of the scenario's text. Only the scenario's own words are sent to the AI.
     *
     * @param  list<string>  $locales
     */
    public function aiTranslate(Activity $a, array $locales, User $actor): Activity
    {
        $draft = self::draftOf($a);
        $source = ['steps' => []];
        foreach ($draft['scenario']['steps'] as $st) {
            $source['steps'][$st['id']] = array_filter(['prompt' => $st['prompt'], 'why' => $st['why'], 'hints' => $st['hints'], 'options' => $st['options'] ?? null,
                'wrong' => array_column($st['wrong'], 'message')], fn ($v) => $v !== null && $v !== '' && $v !== []);
        }
        if ($source['steps'] === []) {
            throw ValidationException::withMessages(['ai' => 'Write the English scenario first.']);
        }
        if (($draft['scenario']['job']['customer'] ?? '') !== '') {
            $source['job'] = ['customer' => $draft['scenario']['job']['customer']];
        }
        foreach (array_intersect($locales, LessonContent::LOCALES) as $loc) {
            try {
                $data = $this->ai->json(
                    'You translate instructions for a workplace simulator used by South African learners. Use everyday, natural '.self::LANGUAGE_NAMES[$loc]
                    .' as spoken today. Keep technical terms workers really use in English (RAM, PC, Wi-Fi). Keep the same meaning and the same number of list items.',
                    'Translate every value into '.self::LANGUAGE_NAMES[$loc].". Keep the keys and structure exactly.\n".json_encode($source, JSON_UNESCAPED_UNICODE),
                );
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['ai' => $e->getMessage()]);
            }
            $draft['i18n'][$loc] = $data;
            $draft['i18n'] = self::cleanI18n($draft['i18n'], $draft['scenario']);
            $draft['review'][$loc] = ['ai' => true];
        }
        $a->update(['draft' => $draft['scenario'] + ['i18n' => $draft['i18n'], 'review' => $draft['review']], 'draft_updated_at' => now()]);
        $this->audit->record('activities.ai_translated', $a, null, ['locales' => $locales], $actor);

        return $a;
    }

    public function review(Activity $a, string $locale, bool $reviewed, User $actor): Activity
    {
        $draft = self::draftOf($a);
        if ($locale !== 'en' && ($draft['i18n'][$locale] ?? []) === []) {
            throw ValidationException::withMessages(['locale' => 'There is no translation to review yet.']);
        }
        if ($reviewed) {
            $draft['review'][$locale] = ['by' => $actor->name, 'at' => now()->toIso8601String()];
        } else {
            unset($draft['review'][$locale]);
        }
        $a->update(['draft' => $draft['scenario'] + ['i18n' => $draft['i18n'], 'review' => $draft['review']]]);
        $this->audit->record($reviewed ? 'activities.reviewed' : 'activities.review_withdrawn', $a, null, ['locale' => $locale], $actor);

        return $a;
    }

    public function publish(Activity $a, User $actor): Activity
    {
        $draft = self::draftOf($a);
        [$scenario, $problems] = ScenarioContent::clean($draft['scenario']);
        if (! isset($draft['review']['en']['by'])) {
            $problems[] = 'A subject expert must review the English scenario before it is published.';
        }
        $lessons = Activity::query()->where('programme_version_id', $a->programme_version_id)->where('kind', Activity::LESSON)->pluck('code')->all();
        foreach ($scenario['steps'] as $i => $st) {
            if (($st['lesson']['code'] ?? null) !== null && ! in_array($st['lesson']['code'], $lessons, true)) {
                $problems[] = 'Step '.($i + 1).": the lesson {$st['lesson']['code']} does not exist in this programme.";
            }
        }
        if ($problems !== []) {
            throw ValidationException::withMessages(['publish' => $problems]);
        }
        $content = $scenario + ['i18n' => $draft['i18n'], 'review' => $draft['review']];
        $changed = $content !== $a->content;
        $a->fill(['content' => $content, 'draft' => null, 'content_version' => $a->status === Activity::PUBLISHED && $changed ? $a->content_version + 1 : $a->content_version])->save();
        $this->catalogue->publish($a, $actor);

        return $a;
    }

    /**
     * The English words of a scenario (what translations depend on).
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function english(array $s): array
    {
        return ['job' => $s['job']['customer'] ?? null, 'steps' => array_map(fn ($st) => [$st['id'], $st['prompt'], $st['why'], $st['hints'], $st['options'] ?? null, array_column($st['wrong'], 'message')], (array) ($s['steps'] ?? []))];
    }
}
