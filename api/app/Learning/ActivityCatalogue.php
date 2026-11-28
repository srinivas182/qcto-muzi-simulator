<?php

declare(strict_types=1);

namespace App\Learning;

use App\Audit\AuditLogger;
use App\Models\Activity;
use App\Models\OutcomeCoverage;
use App\Models\ProgrammeVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HQ's lessons and scenarios for a programme version. Course Studio (4.2) and Scenario Studio (4.5) build on this.
 * Publishing an activity marks its outcomes as "linked" on the coverage map (course or simulator channel).
 */
final class ActivityCatalogue
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{kind: string, code: string, title: string, summary?: string|null, required?: bool, weight?: int, minutes?: int, content?: array<string, mixed>}  $input
     * @param  list<string>  $outcomeCodes
     */
    public function create(ProgrammeVersion $version, array $input, array $outcomeCodes, ?User $actor = null): Activity
    {
        $known = DB::table('outcomes')->where('programme_version_id', $version->id)->whereIn('code', $outcomeCodes)->pluck('code')->all();
        if (count($known) !== count(array_unique($outcomeCodes))) {
            throw ValidationException::withMessages(['outcomes' => 'Link only outcomes of this programme version.']);
        }

        return DB::transaction(function () use ($version, $input, $outcomeCodes, $actor): Activity {
            $activity = Activity::query()->create($input + [
                'programme_version_id' => $version->id,
                'position' => (int) Activity::query()->where('programme_version_id', $version->id)->max('position') + 1,
                'updated_by' => $actor?->id,
            ]);
            DB::table('activity_outcomes')->insert(array_map(fn (string $c) => ['activity_id' => $activity->id, 'outcome_code' => $c], array_values(array_unique($outcomeCodes))));
            $this->audit->record('activities.created', $activity, null, ['code' => $activity->code, 'kind' => $activity->kind], $actor);

            return $activity;
        });
    }

    public function publish(Activity $activity, ?User $actor = null): Activity
    {
        DB::transaction(function () use ($activity, $actor): void {
            $activity->update(['status' => Activity::PUBLISHED, 'published_at' => now()]);
            $channel = $activity->kind === Activity::SCENARIO ? 'simulator' : 'course';
            $programme = $activity->version->programme_id;
            foreach ($activity->outcomeCodes() as $code) {
                OutcomeCoverage::query()->updateOrCreate(
                    ['programme_id' => $programme, 'outcome_code' => $code, 'channel' => $channel],
                    ['status' => 'linked', 'title' => $activity->title, 'updated_by' => $actor?->id],
                );
            }
            $this->audit->record('activities.published', $activity, null, ['code' => $activity->code], $actor);
        });

        return $activity;
    }
}
