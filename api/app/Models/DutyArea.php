<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $programme_version_id
 * @property int $number
 * @property string $title
 */
class DutyArea extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** @return HasMany<Outcome, $this> */
    public function outcomes(): HasMany
    {
        return $this->hasMany(Outcome::class)->orderBy('position');
    }

    /** @return HasMany<EvidenceRequirement, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(EvidenceRequirement::class)->orderBy('position');
    }
}
