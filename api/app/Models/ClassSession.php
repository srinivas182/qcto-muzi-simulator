<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A live class: one scenario, run by the facilitator with the whole cohort at the same time.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $cohort_id
 * @property string $activity_id
 * @property string $mode
 * @property string|null $started_by
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property-read Activity $activity
 * @property-read Cohort $cohort
 */
class ClassSession extends Model
{
    use BelongsToTenant, HasUuids;

    /** A class left open is closed automatically after this long. */
    public const MAX_HOURS = 4;

    protected $fillable = ['cohort_id', 'activity_id', 'mode', 'started_by', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<Cohort, $this> */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    public function isLive(): bool
    {
        return $this->ended_at === null && $this->started_at->gt(now()->subHours(self::MAX_HOURS));
    }

    /** The cohort's live class, if any. */
    public static function liveFor(string $cohortId): ?self
    {
        return self::query()->with('activity')->where('cohort_id', $cohortId)->whereNull('ended_at')
            ->where('started_at', '>', now()->subHours(self::MAX_HOURS))->latest('started_at')->first();
    }
}
