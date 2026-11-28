<?php

declare(strict_types=1);

namespace App\Learning;

use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\LearnerRating;

/**
 * The pilot measures agreed for Sprint 4 (one of Muzi's cohorts), per class:
 *  - completion of the first simulator, with time taken and tries;
 *  - how often learners ask for help (hints and the coach), and the steps they ask about most;
 *  - data used per learner (offline packs downloaded);
 *  - the 3-question learner rating.
 * We fix what the pilot shows before producing content for the other 12 programmes.
 */
final class PilotMeasures
{
    /** @return array<string, mixed> */
    public function forCohort(Cohort $cohort): array
    {
        $enrolments = Enrolment::query()->where('cohort_id', $cohort->id)->whereIn('status', [Enrolment::ENROLLED, Enrolment::ACTIVE, Enrolment::COMPLETED])->get();
        $ids = $enrolments->pluck('id');
        $first = Activity::query()->where('programme_version_id', $cohort->programme_version_id)->where('kind', Activity::SCENARIO)->where('status', Activity::PUBLISHED)->orderBy('position')->first();
        $attempts = Attempt::query()->whereIn('enrolment_id', $ids)->whereNotNull('finished_at')->orderBy('started_at')->get();

        // First simulator: completion, time and tries until the first completed run.
        $firstSim = null;
        if ($first !== null) {
            $mine = $attempts->where('activity_id', $first->id)->groupBy('enrolment_id');
            $minutes = [];
            $tries = [];
            foreach ($mine as $list) {
                $n = 0;
                foreach ($list as $a) {
                    $n++;
                    if ($a->completed) {
                        $minutes[] = round($a->duration_seconds / 60, 1);
                        $tries[] = $n;
                        break;
                    }
                }
            }
            $firstSim = [
                'code' => $first->code, 'title' => $first->title, 'learners' => $ids->count(), 'started' => $mine->count(), 'completed' => count($tries),
                'completion' => $ids->count() > 0 ? (int) round(100 * count($tries) / $ids->count()) : 0,
                'median_minutes' => self::median($minutes), 'median_tries' => self::median($tries),
            ];
        }

        // Help: hints and coach "help" per finished simulator run, and the steps asked about most.
        $runs = $attempts->whereIn('mode', ['practice', 'job']);
        $asks = [];
        $total = 0;
        foreach ($runs as $a) {
            foreach ((array) ($a->result['events'] ?? []) as $e) {
                if (($e['action'] ?? '') === 'hint' || (($e['action'] ?? '') === 'coach' && ($e['value'] ?? '') === 'help')) {
                    $total++;
                    $key = $a->activity_id.'|'.($e['step'] ?? '');
                    $asks[$key] = ($asks[$key] ?? 0) + 1;
                }
            }
        }
        arsort($asks);
        $prompts = Activity::query()->whereIn('id', array_unique(array_map(fn ($k) => explode('|', $k)[0], array_keys($asks))))->get()->keyBy('id');
        $topSteps = collect(array_slice($asks, 0, 3, true))->map(function (int $n, string $key) use ($prompts): array {
            [$activityId, $stepId] = explode('|', $key);
            $step = collect((array) ($prompts->get($activityId)?->content['steps'] ?? []))->firstWhere('id', $stepId);

            return ['activity' => $prompts->get($activityId)?->code, 'step' => $stepId, 'prompt' => is_array($step) ? $step['prompt'] : $stepId, 'asks' => $n];
        })->values()->all();

        $downloaders = $enrolments->where('pack_downloads', '>', 0);
        $ratings = LearnerRating::query()->whereIn('enrolment_id', $ids)->get();

        return [
            'first_simulator' => $firstSim,
            'help' => ['per_run' => $runs->count() > 0 ? round($total / $runs->count(), 1) : 0.0, 'runs' => $runs->count(), 'top_steps' => $topSteps],
            'data' => ['learners_offline' => $downloaders->count(), 'avg_mb' => $downloaders->count() > 0 ? round($downloaders->avg('pack_bytes') / 1_048_576, 1) : 0.0],
            'rating' => ['count' => $ratings->count(), 'easy' => self::avg($ratings->pluck('easy')->all()), 'real' => self::avg($ratings->pluck('real')->all()), 'ready' => self::avg($ratings->pluck('ready')->all())],
        ];
    }

    /** @param list<int|float> $xs */
    private static function median(array $xs): ?float
    {
        if ($xs === []) {
            return null;
        }
        sort($xs);
        $m = intdiv(count($xs), 2);

        return (float) (count($xs) % 2 ? $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2);
    }

    /** @param list<int> $xs */
    private static function avg(array $xs): ?float
    {
        return $xs === [] ? null : round(array_sum($xs) / count($xs), 1);
    }
}
