<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A learner's workplace placement (Sprint 5.3): where they work and who mentors them.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $enrolment_id
 * @property string $employer
 * @property string|null $site
 * @property string $mentor_name
 * @property string $mentor_phone
 * @property string|null $mentor_user_id
 * @property string $confirm_by link · account
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property-read Enrolment $enrolment
 */
class Workplace extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['enrolment_id', 'employer', 'site', 'mentor_name', 'mentor_phone', 'mentor_user_id', 'confirm_by', 'starts_on', 'ends_on', 'created_by'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    /** @return BelongsTo<Enrolment, $this> */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }
}
