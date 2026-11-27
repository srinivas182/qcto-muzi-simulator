<?php

declare(strict_types=1);

namespace App\Programmes;

use App\Models\ProgrammeVersion;

/**
 * "What changed" between two versions, in plain terms: added, removed and reworded items.
 * Outcomes are matched by their stable code, duty areas by number, evidence by duty area and wording.
 */
final class VersionComparison
{
    /** @return list<array{area: string, change: string, item: string, before?: string|null, after?: string|null}> */
    public static function compare(ProgrammeVersion $from, ProgrammeVersion $to): array
    {
        $a = ProgrammePresenter::version($from);
        $b = ProgrammePresenter::version($to);
        $changes = [];

        foreach (['summary' => 'Summary', 'purpose' => 'Purpose'] as $field => $label) {
            if ($a[$field] !== $b[$field]) {
                $changes[] = ['area' => 'overview', 'change' => 'changed', 'item' => $label, 'before' => $a[$field], 'after' => $b[$field]];
            }
        }
        foreach (['occupations' => 'Occupation', 'workplace_requirements' => 'Workplace requirement', 'tools' => 'Tool or system', 'to_confirm' => 'Item to confirm'] as $field => $label) {
            /** @var list<string> $before */
            $before = $a[$field];
            /** @var list<string> $after */
            $after = $b[$field];
            foreach (array_diff($after, $before) as $item) {
                $changes[] = ['area' => 'overview', 'change' => 'added', 'item' => $label, 'after' => $item];
            }
            foreach (array_diff($before, $after) as $item) {
                $changes[] = ['area' => 'overview', 'change' => 'removed', 'item' => $label, 'before' => $item];
            }
        }

        /** @var array<int, array{number: int, title: string, outcomes: list<array{code: string, statement: string, plain_words: string|null}>, evidence: list<array{description: string}>}> $areasA */
        $areasA = array_column($a['duty_areas'], null, 'number');
        /** @var array<int, array{number: int, title: string, outcomes: list<array{code: string, statement: string, plain_words: string|null}>, evidence: list<array{description: string}>}> $areasB */
        $areasB = array_column($b['duty_areas'], null, 'number');

        foreach ($areasB as $n => $area) {
            if (! isset($areasA[$n])) {
                $changes[] = ['area' => 'duty_areas', 'change' => 'added', 'item' => "Duty area {$n}", 'after' => $area['title']];
            } elseif ($areasA[$n]['title'] !== $area['title']) {
                $changes[] = ['area' => 'duty_areas', 'change' => 'changed', 'item' => "Duty area {$n}", 'before' => $areasA[$n]['title'], 'after' => $area['title']];
            }
        }
        foreach ($areasA as $n => $area) {
            if (! isset($areasB[$n])) {
                $changes[] = ['area' => 'duty_areas', 'change' => 'removed', 'item' => "Duty area {$n}", 'before' => $area['title']];
            }
        }

        $outA = self::outcomes($areasA);
        $outB = self::outcomes($areasB);
        foreach ($outB as $code => $o) {
            if (! isset($outA[$code])) {
                $changes[] = ['area' => 'outcomes', 'change' => 'added', 'item' => $code, 'after' => $o['statement']];
            } elseif ($outA[$code]['statement'] !== $o['statement'] || $outA[$code]['plain_words'] !== $o['plain_words']) {
                $changes[] = ['area' => 'outcomes', 'change' => 'changed', 'item' => $code, 'before' => $outA[$code]['statement'], 'after' => $o['statement']];
            }
        }
        foreach ($outA as $code => $o) {
            if (! isset($outB[$code])) {
                $changes[] = ['area' => 'outcomes', 'change' => 'removed', 'item' => $code, 'before' => $o['statement']];
            }
        }

        foreach ($areasB as $n => $area) {
            $before = array_column($areasA[$n]['evidence'] ?? [], 'description');
            $after = array_column($area['evidence'], 'description');
            foreach (array_diff($after, $before) as $item) {
                $changes[] = ['area' => 'evidence', 'change' => 'added', 'item' => "Duty area {$n}", 'after' => $item];
            }
            foreach (array_diff($before, $after) as $item) {
                $changes[] = ['area' => 'evidence', 'change' => 'removed', 'item' => "Duty area {$n}", 'before' => $item];
            }
        }

        return $changes;
    }

    /**
     * @param  array<int, array{outcomes: list<array{code: string, statement: string, plain_words: string|null}>}>  $areas
     * @return array<string, array{code: string, statement: string, plain_words: string|null}>
     */
    private static function outcomes(array $areas): array
    {
        $out = [];
        foreach ($areas as $area) {
            foreach ($area['outcomes'] as $o) {
                $out[$o['code']] = $o;
            }
        }

        return $out;
    }
}
