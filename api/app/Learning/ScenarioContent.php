<?php

declare(strict_types=1);

namespace App\Learning;

use Illuminate\Support\Str;

/**
 * The shape of a simulator scenario, and how an attempt at it is scored.
 *
 * A scenario is played on a ready-made scene (the objects a learner can touch). It is a list of steps:
 *
 *   tap     {target}                   touch the right object (switch off at the wall, put on the strap)
 *   choose  {options[], answer}        decide (what is the most likely fault?)
 *   place   {target, slot}             put an object somewhere (new RAM into slot A1); tap-then-tap, no precise drag
 *
 * Every step also has: prompt, hints[] (each more specific; practice mode only), why (shown in the debrief),
 * outcome (QCTO code), lesson {code, block} (where the debrief sends you), critical (a mistake here fails
 * real-job mode: safety), sets {object: state} (how the scene changes once the step is done) and
 * wrong[] {on, effect, message} (what happens when you do the wrong thing: mistakes have consequences).
 *
 * Branching: a scenario may list `variants` (different causes of the same symptom, each with its own starting
 * scene). Each run picks one at random, so learners diagnose instead of memorising; a step with `only` appears
 * only in those variants.
 *
 * Scoring is done on the server from the learner's events, never trusted from the device. The same rules run
 * on the device (packages/core/src/scenario.ts) so the learner sees the same result offline.
 */
final class ScenarioContent
{
    /** Ready-made scenes and the objects in them. New scenes are added here and in the web scene library. */
    public const SCENES = [
        'pc-bench' => ['pc-power', 'wall-switch', 'strap', 'case-panel', 'ram-old', 'ram-new', 'slot-a1', 'monitor', 'screwdriver', 'psu-cable'],
    ];

    public const TYPES = ['tap', 'choose', 'place'];

    public const EFFECTS = ['none', 'spark', 'static', 'beep', 'customer'];

    public const MAX_STEPS = 40;

    public const MAX_VARIANTS = 4;

    /** Translatable fields per step. Wrong-move messages are translated as wrong[i]. */
    public const TEXT_FIELDS = ['prompt', 'hints', 'why', 'options', 'wrong'];

    /**
     * Normalise a scenario and list what is wrong with it.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function clean(mixed $content): array
    {
        $c = is_array($content) ? $content : [];
        $problems = [];
        $scene = (string) ($c['scene'] ?? '');
        if (! isset(self::SCENES[$scene])) {
            return [['scene' => '', 'pass_mark' => 70, 'job' => null, 'variants' => [], 'steps' => []], ['Choose a scene.']];
        }
        $objects = self::SCENES[$scene];
        $s = fn (array $a, string $k, int $max) => mb_substr(trim((string) ($a[$k] ?? '')), 0, $max);
        $variants = [];
        foreach (array_slice(is_array($c['variants'] ?? null) ? array_values($c['variants']) : [], 0, self::MAX_VARIANTS) as $i => $v) {
            if (! is_array($v) || preg_match('/^[a-z0-9]{2,12}$/', (string) ($v['id'] ?? '')) !== 1 || isset($variants[$v['id']])) {
                $problems[] = 'Cause '.($i + 1).' needs a short id (letters and numbers).';

                continue;
            }
            $start = [];
            foreach ((array) ($v['start'] ?? []) as $obj => $state) {
                if (in_array($obj, $objects, true) && preg_match('/^[a-z-]{1,16}$/', (string) $state) === 1) {
                    $start[$obj] = (string) $state;
                }
            }
            $variants[$v['id']] = ['id' => (string) $v['id'], 'label' => $s($v, 'label', 80), 'start' => $start];
        }
        $variants = array_values($variants);
        $variantIds = array_column($variants, 'id');
        count($variants) === 1 && $problems[] = 'Add a second cause, or remove the only one: one cause is the same as none.';
        $steps = [];
        foreach (array_slice(is_array($c['steps'] ?? null) ? array_values($c['steps']) : [], 0, self::MAX_STEPS) as $i => $st) {
            $n = $i + 1;
            if (! is_array($st) || ! in_array($st['type'] ?? null, self::TYPES, true)) {
                $problems[] = "Step {$n} has an unknown type.";

                continue;
            }
            $step = [
                'id' => preg_match('/^[a-z0-9]{4,12}$/', (string) ($st['id'] ?? '')) === 1 ? (string) $st['id'] : Str::lower(Str::random(8)),
                'type' => $st['type'], 'prompt' => $s($st, 'prompt', 300),
                'hints' => array_values(array_filter(array_map(fn ($h) => mb_substr(trim((string) $h), 0, 200), array_slice((array) ($st['hints'] ?? []), 0, 3)), fn ($h) => $h !== '')),
                'why' => $s($st, 'why', 400), 'outcome' => ($st['outcome'] ?? null) ? mb_substr((string) $st['outcome'], 0, 20) : null,
                'lesson' => is_array($st['lesson'] ?? null) && ($st['lesson']['code'] ?? '') !== '' ? ['code' => mb_substr((string) $st['lesson']['code'], 0, 20), 'block' => ($st['lesson']['block'] ?? null) ? (string) $st['lesson']['block'] : null] : null,
                'critical' => (bool) ($st['critical'] ?? false),
                'only' => array_values(array_intersect(array_map('strval', (array) ($st['only'] ?? [])), $variantIds)),
                'sets' => [],
                'wrong' => [],
            ];
            $step['prompt'] === '' && $problems[] = "Step {$n}: write the instruction.";
            if ($st['type'] === 'choose') {
                $step['options'] = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 160), array_slice((array) ($st['options'] ?? []), 0, 5)), fn ($o) => $o !== ''));
                $step['answer'] = (int) ($st['answer'] ?? 0);
                (count($step['options']) < 2 || $step['answer'] < 0 || $step['answer'] >= count($step['options'])) && $problems[] = "Step {$n}: give at least two choices and mark the right one.";
            } else {
                $step['target'] = (string) ($st['target'] ?? '');
                in_array($step['target'], $objects, true) || $problems[] = "Step {$n}: choose the object to ".($st['type'] === 'tap' ? 'touch' : 'move').'.';
                if ($st['type'] === 'place') {
                    $step['slot'] = (string) ($st['slot'] ?? '');
                    in_array($step['slot'], $objects, true) && $step['slot'] !== $step['target'] || $problems[] = "Step {$n}: choose where it goes.";
                }
            }
            foreach ((array) ($st['sets'] ?? []) as $obj => $state) {
                if (in_array($obj, $objects, true) && preg_match('/^[a-z-]{1,16}$/', (string) $state) === 1) {
                    $step['sets'][$obj] = (string) $state;
                }
            }
            foreach (array_slice((array) ($st['wrong'] ?? []), 0, 6) as $w) {
                $on = (string) ($w['on'] ?? '*');
                if ($on === '*' || in_array($on, $objects, true) || preg_match('/^option:\d$/', $on) === 1) {
                    $step['wrong'][] = ['on' => $on, 'effect' => in_array($w['effect'] ?? 'none', self::EFFECTS, true) ? (string) $w['effect'] : 'none', 'message' => $s((array) $w, 'message', 300)];
                }
            }
            $steps[] = $step;
        }
        $steps === [] && $problems[] = 'Add at least one step.';
        foreach ($variantIds as $vid) {
            self::stepsFor(['steps' => $steps], $vid) === [] && $problems[] = "The cause \"{$vid}\" has no steps.";
        }
        $job = is_array($c['job'] ?? null) ? ['minutes' => max(1, min(60, (int) ($c['job']['minutes'] ?? 15))), 'customer' => $s($c['job'], 'customer', 300)] : null;

        return [['scene' => $scene, 'pass_mark' => max(0, min(100, (int) ($c['pass_mark'] ?? 70))), 'job' => $job, 'variants' => $variants, 'steps' => $steps], $problems];
    }

    /**
     * The steps of one run: all of them, or for a branching scenario those of the chosen cause.
     *
     * @param  array<string, mixed>  $content
     * @return list<array<string, mixed>>
     */
    public static function stepsFor(array $content, ?string $variant): array
    {
        return array_values(array_filter((array) ($content['steps'] ?? []), fn ($st) => ($st['only'] ?? []) === [] || in_array($variant, $st['only'], true)));
    }

    /**
     * The text a learner sees in a language: reviewed translations only, otherwise English.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    public static function localise(array $content, string $locale): array
    {
        $tr = isset($content['review'][$locale]['by']) ? (array) ($content['i18n'][$locale] ?? []) : [];
        $out = array_intersect_key($content, array_flip(['scene', 'pass_mark', 'job', 'variants', 'steps']));
        // Learners never see what each cause is called: that would give the answer away.
        $out['variants'] = array_map(fn (array $v) => ['id' => $v['id'], 'start' => $v['start']], (array) ($out['variants'] ?? []));
        if (isset($tr['job']['customer'], $out['job'])) {
            $out['job']['customer'] = (string) $tr['job']['customer'];
        }
        $out['steps'] = array_map(function (array $st) use ($tr) {
            $t = (array) ($tr['steps'][$st['id']] ?? []);
            foreach (['prompt', 'why'] as $f) {
                isset($t[$f]) && $st[$f] = (string) $t[$f];
            }
            foreach (['hints', 'options'] as $f) {
                isset($t[$f], $st[$f]) && is_array($t[$f]) && count($t[$f]) === count($st[$f]) && $st[$f] = array_values(array_map('strval', $t[$f]));
            }
            foreach ((array) ($t['wrong'] ?? []) as $i => $msg) {
                isset($st['wrong'][$i]) && $st['wrong'][$i]['message'] = (string) $msg;
            }

            return $st;
        }, (array) ($out['steps'] ?? []));

        return $out;
    }

    /**
     * Score an attempt from the learner's events. Events are walked in order against the steps: a hint or a
     * wrong move on the current step counts against it; the right move moves on. Events for other steps are
     * ignored, so a replayed or tampered log cannot skip steps.
     *
     * Each step is worth 1, less 0.25 per mistake and 0.15 per hint (never below 0). Passed means every step was
     * done and the score reaches the pass mark; in real-job mode a mistake on a critical (safety) step also fails.
     *
     * @param  array<string, mixed>  $content
     * @param  mixed  $events  list of {step, action: tap|choose|place|hint, value}
     * @param  string|null  $variant  the cause played, for branching scenarios
     * @return array{completed: bool, score: int, passed: bool, critical: bool, steps: array<string, array{mistakes: int, hints: int, done: bool}>}
     */
    public static function score(array $content, mixed $events, string $mode, ?string $variant = null): array
    {
        $ids = array_column((array) ($content['variants'] ?? []), 'id');
        // A branching scenario must say which cause was played; an unknown cause scores nothing.
        $steps = $ids === [] ? self::stepsFor($content, null) : (in_array($variant, $ids, true) ? self::stepsFor($content, $variant) : []);
        $per = [];
        foreach ($steps as $st) {
            $per[$st['id']] = ['mistakes' => 0, 'hints' => 0, 'done' => false];
        }
        $i = 0;
        $critical = false;
        foreach (array_slice(is_array($events) ? $events : [], 0, 2000) as $e) {
            if ($i >= count($steps) || ! is_array($e) || ($e['step'] ?? null) !== $steps[$i]['id']) {
                continue;
            }
            $st = $steps[$i];
            $action = (string) ($e['action'] ?? '');
            if ($action === 'coach') {
                // Asking the coach for help costs like a hint; asking "why" after a mistake is free.
                ($e['value'] ?? '') === 'help' && $per[$st['id']]['hints']++;

                continue;
            }
            if ($action === 'hint') {
                $per[$st['id']]['hints']++;

                continue;
            }
            if (self::isRight($st, $action, (string) ($e['value'] ?? ''))) {
                $per[$st['id']]['done'] = true;
                $i++;
            } else {
                $per[$st['id']]['mistakes']++;
                $critical = $critical || ($st['critical'] ?? false);
            }
        }
        $completed = $steps !== [] && $i === count($steps);
        $points = array_sum(array_map(fn ($p) => $p['done'] ? max(0, 1 - 0.25 * $p['mistakes'] - 0.15 * $p['hints']) : 0, $per));
        $score = $steps === [] ? 0 : (int) round(100 * $points / count($steps));
        $passed = $completed && $score >= (int) ($content['pass_mark'] ?? 70) && ! ($mode === 'job' && $critical);

        return ['completed' => $completed, 'score' => $score, 'passed' => $passed, 'critical' => $critical, 'steps' => $per];
    }

    /** @param array<string, mixed> $step */
    public static function isRight(array $step, string $action, string $value): bool
    {
        return match ($step['type']) {
            'tap' => $action === 'tap' && $value === $step['target'],
            'choose' => $action === 'choose' && $value === (string) $step['answer'],
            'place' => $action === 'place' && $value === $step['target'].'>'.$step['slot'],
            default => false,
        };
    }
}
