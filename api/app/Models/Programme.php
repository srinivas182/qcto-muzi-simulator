<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A programme in the platform catalogue (shared by all providers; not tenant data).
 *
 * @property string $id
 * @property string $code
 * @property string $prefix
 * @property string $title
 * @property string $family
 * @property int $position
 * @property string $status
 */
class Programme extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'prefix', 'title', 'family', 'position', 'status'];

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return HasMany<ProgrammeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProgrammeVersion::class)->orderByDesc('number');
    }

    /** @return HasOne<ProgrammeVersion, $this> */
    public function published(): HasOne
    {
        return $this->hasOne(ProgrammeVersion::class)->where('status', ProgrammeVersion::PUBLISHED);
    }

    /** @return HasOne<ProgrammeVersion, $this> */
    public function openDraft(): HasOne
    {
        return $this->hasOne(ProgrammeVersion::class)->whereIn('status', [ProgrammeVersion::DRAFT, ProgrammeVersion::IN_REVIEW]);
    }
}
