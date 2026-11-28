<?php

declare(strict_types=1);

namespace App\Assessment;

use Illuminate\Support\Str;

/**
 * Questions for knowledge tests, and the settings of each kind of assessment task.
 *
 *   single  {prompt, options[], answer: int, explain}       one right option
 *   multi   {prompt, options[], answer: int[], explain}     all right options, nothing else
 *   short   {prompt, model_answer}                           marked by the assessor against the model answer
 * Every question has: id, type, outcome (QCTO code), marks (1–10).
 *
 * Answer keys and model answers never leave the server during a test (5.1b).
 */
final class QuestionBank
{
    public const TYPES = ['single', 'multi', 'short'];

    public const LOCALES = ['zu', 'xh', 'af'];

    /** Text a translator may change. Model answers stay with the assessor in English. */
    public const TEXT_FIELDS = ['prompt', 'options', 'explain'];

    public const MAX_QUESTIONS = 200;

    /**
     * @param  list<string>  $outcomes  the task's outcome codes
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    public static function clean(mixed $questions, array $outcomes): array
    {
        $out = [];
        $problems = [];
        foreach (array_slice(is_array($questions) ? array_values($questions) : [], 0, self::MAX_QUESTIONS) as $i => $q) {
            $n = $i + 1;
            if (! is_array($q) || ! in_array($q['type'] ?? null, self::TYPES, true)) {
                $problems[] = "Question {$n} has an unknown type.";

                continue;
            }
            $s = fn (string $k, int $max) => mb_substr(trim((string) ($q[$k] ?? '')), 0, $max);
            $row = [
                'id' => preg_match('/^[a-z0-9]{6,12}$/', (string) ($q['id'] ?? '')) === 1 ? (string) $q['id'] : Str::lower(Str::random(8)),
                'type' => $q['type'], 'prompt' => $s('prompt', 500),
                'outcome' => in_array($q['outcome'] ?? null, $outcomes, true) ? (string) $q['outcome'] : null,
                'marks' => max(1, min(10, (int) ($q['marks'] ?? 1))),
            ];
            $row['prompt'] === '' && $problems[] = "Question {$n}: write the question.";
            $row['outcome'] === null && $problems[] = "Question {$n}: link it to one of the task's outcomes.";
            if ($q['type'] === 'short') {
                $row['model_answer'] = $s('model_answer', 1000);
                $row['model_answer'] === '' && $problems[] = "Question {$n}: write the model answer the assessor marks against.";
            } else {
                $row['options'] = array_values(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 200), array_slice((array) ($q['options'] ?? []), 0, 6)));
                $row['explain'] = $s('explain', 500);
                $count = count($row['options']);
                if ($q['type'] === 'single') {
                    $row['answer'] = (int) ($q['answer'] ?? -1);
                    $ok = $row['answer'] >= 0 && $row['answer'] < $count;
                } else {
                    $answers = array_values(array_unique(array_filter(array_map('intval', (array) ($q['answer'] ?? [])), fn (int $a) => $a >= 0 && $a < $count)));
                    sort($answers);
                    $row['answer'] = $answers;
                    $ok = $answers !== [];
                }
                if ($count < 2 || in_array('', $row['options'], true) || ! $ok) {
                    $problems[] = "Question {$n}: give at least two choices and mark the right one.";
                }
            }
            $out[] = $row;
        }

        return [$out, $problems];
    }

    /**
     * Settings per kind of task, with safe defaults.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function settings(string $kind, mixed $input, int $questionCount = 0): array
    {
        $in = is_array($input) ? $input : [];
        $int = fn (string $k, int $def, int $min, int $max) => max($min, min($max, (int) ($in[$k] ?? $def)));
        $problems = [];
        switch ($kind) {
            case 'test':
                $s = ['pick' => $int('pick', 10, 1, 100), 'minutes' => $int('minutes', 30, 5, 180), 'pass_mark' => $int('pass_mark', 70, 1, 100), 'attempts' => $int('attempts', 2, 1, 5),
                    'integrity' => ($in['integrity'] ?? 'light') === 'camera' ? 'camera' : 'light'];
                if ($s['pick'] > $questionCount) {
                    $problems[] = "The bank has {$questionCount} questions; each test draws {$s['pick']}. Add questions or draw fewer.";
                }
                break;
            case 'observation':
                $items = array_values(array_filter(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 200), array_slice((array) ($in['items'] ?? []), 0, 30)), fn ($x) => $x !== ''));
                $s = ['items' => $items];
                $items === [] && $problems[] = 'Add at least one thing the assessor must see.';
                break;
            case 'logbook':
                $s = ['min_days' => $int('min_days', 20, 1, 400)];
                break;
            default: // simulator: a real-job run, reviewed by the assessor
                $s = ['mode' => 'job'];
        }

        return [$s, $problems];
    }

    /**
     * Keep only translations of text that exists, in the same shape as the English.
     *
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    public static function cleanI18n(mixed $i18n, array $questions): array
    {
        $byId = [];
        foreach ($questions as $q) {
            $byId[$q['id']] = $q;
        }
        $out = [];
        foreach (self::LOCALES as $loc) {
            foreach ((array) (((array) $i18n)[$loc] ?? []) as $id => $fields) {
                $q = $byId[$id] ?? null;
                foreach ($q === null ? [] : (array) $fields as $f => $v) {
                    if (! in_array($f, self::TEXT_FIELDS, true) || ($f !== 'prompt' && $q['type'] === 'short')) {
                        continue;
                    }
                    $out[$loc][$id][$f] = is_array($v)
                        ? array_slice(array_values(array_map(fn ($x) => mb_substr((string) $x, 0, 200), $v)), 0, count($q['options'] ?? []))
                        : mb_substr((string) $v, 0, 500);
                }
            }
        }

        return $out;
    }
}
