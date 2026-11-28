<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Seats on one programme, sold by HQ (platform data). Frozen once published.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property string $programme_id
 * @property bool $includes_simulators
 * @property bool $includes_courses
 * @property string $billing
 * @property int|null $term_months
 * @property int $min_seats
 * @property string $status
 * @property string|null $created_by
 * @property Carbon|null $published_at
 * @property Carbon|null $retired_at
 * @property-read Programme $programme
 * @property-read Collection<int, PackagePrice> $prices
 */
class Package extends Model
{
    use HasUuids;

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const RETIRED = 'retired';

    public const TERM = 'term';

    public const MONTHLY = 'monthly';

    protected $fillable = ['code', 'name', 'description', 'programme_id', 'includes_simulators', 'includes_courses', 'billing', 'term_months', 'min_seats', 'status', 'created_by', 'published_at', 'retired_at'];

    protected function casts(): array
    {
        return [
            'includes_simulators' => 'boolean', 'includes_courses' => 'boolean', 'term_months' => 'integer', 'min_seats' => 'integer',
            'published_at' => 'datetime', 'retired_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    /** @return HasMany<PackagePrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PackagePrice::class)->orderBy('from_seats');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
