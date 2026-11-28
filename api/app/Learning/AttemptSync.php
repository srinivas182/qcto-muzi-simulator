<?php

declare(strict_types=1);

namespace App\Learning;

use App\Cohorts\Enrolments;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Enrolment;
use App\Models\Learner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receives attempts from a learner's device: online right away, or in a batch after being offline.
 *
 * Safe to repeat: attempts are matched on the device's client_id, and a finished attempt never changes.
 * Each attempt is checked on its own and accepted or rejected with a reason, so one bad record never blocks
 * the rest of the queue. Afterwards the progress of every enrolment touched is recalculated.
 */
final class AttemptSync
{
    public const MAX_BATCH = 50;

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{accepted: list<string>, rejected: list<array{client_id: string, reason: string}>, progress: array<string, int>}
     */
    public function sync(Learner $learner, array $items): array
    {
        $enrolments = Enrolment::query()->where('learner_id', $learner->id)
            ->whereIn('status', [Enrolment::ENROLLED, Enrolment::ACTIVE, Enrolment::COMPLETED])->get()->keyBy('id');
        $activityIds = array_values(array_unique(array_map(fn ($i) => (string) ($i['activity_id'] ?? ''), $items)));
        $activities = Activity::query()->whereIn('id', array_filter($activityIds, fn ($id) => preg_match('/^[0-9a-f-]{36}$/i', $id) === 1))
            ->where('status', Activity::PUBLISHED)->get()->keyBy('id');
        $accepted = [];
        $rejected = [];
        $touched = [];

        foreach ($items as $item) {
            $clientId = (string) ($item['client_id'] ?? '');
            $reason = $this->check($item, $enrolments, $activities);
            if ($reason !== null) {
                $rejected[] = ['client_id' => $clientId, 'reason' => $reason];

                continue;
            }
            /** @var Enrolment $enrolment */
            $enrolment = $enrolments->get($item['enrolment_id']);
            /** @var Activity $activity */
            $activity = $activities->get($item['activity_id']);

            $outcome = DB::transaction(function () use ($item, $clientId, $enrolment, $activity): string {
                $existing = Attempt::query()->where('client_id', $clientId)->lockForUpdate()->first();
                if ($existing !== null && $existing->enrolment_id !== $enrolment->id) {
                    return 'client_id_in_use';
                }
                if ($existing !== null && $existing->finished_at !== null) {
                    return 'ok'; // already final: repeating a sync changes nothing
                }
                $finished = isset($item['finished_at']) ? Carbon::parse((string) $item['finished_at']) : null;
                $result = is_array($item['result'] ?? null) ? $item['result'] : [];
                $completed = $finished !== null && (bool) ($item['completed'] ?? false);
                $score = isset($item['score']) ? max(0, min(100, (int) $item['score'])) : null;
                if ($activity->kind === Activity::SCENARIO) {
                    // Simulator results are worked out here from the learner's moves, never taken from the device.
                    $variant = isset($result['variant']) ? (string) $result['variant'] : null;
                    $scored = ScenarioContent::score($activity->content ?? [], $result['events'] ?? [], (string) $item['mode'], $variant);
                    $completed = $finished !== null && $scored['completed'];
                    $score = $finished !== null ? $scored['score'] : null;
                    $result = ['events' => array_slice(array_values(array_filter((array) ($result['events'] ?? []), 'is_array')), 0, 2000), 'variant' => $variant]
                        + ($finished !== null ? ['passed' => $scored['passed'], 'critical' => $scored['critical'], 'steps' => $scored['steps']] : []);
                }
                $values = [
                    'enrolment_id' => $enrolment->id, 'activity_id' => $activity->id, 'mode' => $item['mode'],
                    'content_version' => (int) ($item['content_version'] ?? $activity->content_version),
                    'started_at' => Carbon::parse((string) $item['started_at']), 'finished_at' => $finished,
                    'completed' => $completed,
                    'score' => $score,
                    'duration_seconds' => max(0, min(86400, (int) ($item['duration_seconds'] ?? 0))),
                    'result' => $result,
                    'device' => isset($item['device']) ? mb_substr((string) $item['device'], 0, 40) : null,
                ];
                $existing === null ? Attempt::query()->create($values + ['client_id' => $clientId]) : $existing->update($values);
                Enrolments::markStarted($enrolment);

                return 'ok';
            });
            if ($outcome !== 'ok') {
                $rejected[] = ['client_id' => $clientId, 'reason' => $outcome];

                continue;
            }
            $accepted[] = $clientId;
            $touched[$enrolment->id] = $enrolment;
        }

        $progress = [];
        foreach ($touched as $id => $enrolment) {
            $progress[$id] = Progress::refresh($enrolment->refresh());
        }

        return ['accepted' => $accepted, 'rejected' => $rejected, 'progress' => $progress];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<string, Enrolment>  $enrolments
     * @param  Collection<string, Activity>  $activities
     */
    private function check(array $item, $enrolments, $activities): ?string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) ($item['client_id'] ?? '')) !== 1) {
            return 'invalid_client_id';
        }
        $enrolment = $enrolments->get((string) ($item['enrolment_id'] ?? ''));
        if ($enrolment === null) {
            return 'not_your_enrolment';
        }
        $activity = $activities->get((string) ($item['activity_id'] ?? ''));
        if ($activity === null || $activity->programme_version_id !== $enrolment->programme_version_id) {
            return 'activity_not_in_your_programme';
        }
        if (! in_array($item['mode'] ?? null, Attempt::MODES, true) || ($activity->kind === Activity::LESSON) !== ($item['mode'] === 'lesson')) {
            return 'invalid_mode';
        }
        try {
            $started = Carbon::parse((string) ($item['started_at'] ?? ''));
            $finished = isset($item['finished_at']) ? Carbon::parse((string) $item['finished_at']) : null;
        } catch (\Throwable) {
            return 'invalid_time';
        }
        // Allow a little clock drift on phones, but nothing from the future or from before the enrolment.
        if ($started->gt(now()->addMinutes(10)) || $started->lt($enrolment->seat_taken_at->copy()->subDay()) || ($finished !== null && ($finished->lt($started) || $finished->gt(now()->addMinutes(10))))) {
            return 'invalid_time';
        }

        return null;
    }
}
