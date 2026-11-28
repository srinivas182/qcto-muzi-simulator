<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A class of learners on one programme at one centre, pinned to the programme version it started on.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $centre_id
 * @property string $programme_id
 * @property string $programme_version_id
 * @property string $name
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property string $qcto_type qualification · skills_programme · none
 * @property Carbon|null $qcto_submitted_at
 * @property string $join_code
 * @property bool $join_open
 * @property Carbon|null $archived_at
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property string|null $qcto_qualification_id
 * @property string $readiness_type
 * @property int $qcto_reminder_level
 * @property-read Centre $centre
 * @property-read Programme $programme
 * @property-read ProgrammeVersion $version
 */
class Cohort extends Model
{
    use BelongsToTenant, HasUuids;

    public const QCTO_TYPES = ['qualification', 'skills_programme', 'none'];

    protected $fillable = ['centre_id', 'programme_id', 'programme_version_id', 'name', 'starts_on', 'ends_on', 'qcto_type', 'qcto_submitted_at', 'join_code', 'join_open', 'archived_at', 'created_by', 'qcto_qualification_id', 'readiness_type', 'qcto_reminder_level'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'qcto_submitted_at' => 'datetime', 'join_open' => 'boolean', 'archived_at' => 'datetime'];
    }

    /** @return BelongsTo<Centre, $this> */
    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class);
    }

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /** @return BelongsTo<ProgrammeVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProgrammeVersion::class, 'programme_version_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function facilitators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'cohort_facilitators')->withPivot('tenant_id');
    }

    /** @return HasMany<Enrolment, $this> */
    public function enrolments(): HasMany
    {
        return $this->hasMany(Enrolment::class);
    }
}
