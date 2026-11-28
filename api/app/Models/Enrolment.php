<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A learner in a cohort, holding one seat of a licence pool.
 *
 * Seat rules (decision 2): the seat goes back to the pool if the learner has not started 14 days after
 * enrolment (or after the cohort starts, if later), or is withdrawn before 10% progress. Otherwise it stays used.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $learner_id
 * @property string $cohort_id
 * @property string $programme_id
 * @property string $programme_version_id
 * @property string $licence_pool_id
 * @property bool $holds_seat
 * @property string $status enrolled · active · completed · withdrawn · no_show
 * @property int $progress
 * @property Carbon $seat_taken_at
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property string|null $reason
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon|null $sor_issued_on
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $reminded_at
 * @property int $reminders_sent
 * @property-read Programme $programme
 * @property-read Learner $learner
 * @property-read Cohort $cohort
 * @property-read LicencePool $pool
 */
class Enrolment extends Model
{
    use BelongsToTenant, HasUuids;

    public const ENROLLED = 'enrolled';

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const WITHDRAWN = 'withdrawn';

    public const NO_SHOW = 'no_show';

    public const CURRENT = [self::ENROLLED, self::ACTIVE];

    public const NO_SHOW_DAYS = 14;

    public const FREE_WITHDRAWAL_BELOW = 10;

    protected $fillable = ['learner_id', 'cohort_id', 'programme_id', 'programme_version_id', 'licence_pool_id', 'holds_seat', 'status', 'progress', 'seat_taken_at', 'started_at', 'ended_at', 'reason', 'created_by', 'sor_issued_on', 'last_activity_at', 'reminded_at', 'reminders_sent'];

    protected function casts(): array
    {
        return ['holds_seat' => 'boolean', 'progress' => 'integer', 'seat_taken_at' => 'datetime', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'sor_issued_on' => 'date', 'last_activity_at' => 'datetime', 'reminded_at' => 'datetime', 'reminders_sent' => 'integer'];
    }

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /** @return BelongsTo<Learner, $this> */
    public function learner(): BelongsTo
    {
        return $this->belongsTo(Learner::class);
    }

    /** @return BelongsTo<Cohort, $this> */
    public function cohort(): BelongsTo
    {
        return $this->belongsTo(Cohort::class);
    }

    /** @return BelongsTo<LicencePool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(LicencePool::class, 'licence_pool_id');
    }

    /** The day the seat goes back if the learner has not started. */
    public function noShowDate(Cohort $cohort): Carbon
    {
        $from = $this->seat_taken_at->copy()->startOfDay()->max($cohort->starts_on->copy()->startOfDay());

        return $from->addDays(self::NO_SHOW_DAYS);
    }
}
