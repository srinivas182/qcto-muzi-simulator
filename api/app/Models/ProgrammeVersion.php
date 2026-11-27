<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One version of a programme's workplace content. Published and retired versions are frozen by the database.
 *
 * @property string $id
 * @property string $programme_id
 * @property int $number
 * @property string $status
 * @property string $summary
 * @property string $purpose
 * @property list<string> $occupations
 * @property list<string> $workplace_requirements
 * @property list<string> $tools
 * @property list<string> $to_confirm
 * @property array<string, mixed>|null $source
 * @property string|null $change_notes
 * @property Carbon|null $published_at
 * @property-read Programme $programme
 */
class ProgrammeVersion extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const IN_REVIEW = 'in_review';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'occupations' => 'array', 'workplace_requirements' => 'array', 'tools' => 'array', 'to_confirm' => 'array',
            'source' => 'array', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime', 'published_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function isFrozen(): bool
    {
        return in_array($this->status, [self::PUBLISHED, self::RETIRED], true);
    }

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /** @return HasMany<QualificationRef, $this> */
    public function qualifications(): HasMany
    {
        return $this->hasMany(QualificationRef::class)->orderBy('position');
    }

    /** @return HasMany<DutyArea, $this> */
    public function dutyAreas(): HasMany
    {
        return $this->hasMany(DutyArea::class)->orderBy('number');
    }

    /** @return HasMany<Outcome, $this> */
    public function outcomes(): HasMany
    {
        return $this->hasMany(Outcome::class);
    }

    /** @return HasMany<EvidenceRequirement, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceRequirement::class);
    }
}
