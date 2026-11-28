<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A provider's seats on one package. The price per seat is locked when the pool is first bought, and top-ups
 * use the same price.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $package_id
 * @property string $programme_id
 * @property string $billing
 * @property int|null $term_months
 * @property int $unit_price_cents
 * @property int $seats
 * @property int|null $seats_next_month
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 * @property bool $cancel_at_period_end
 * @property string $status
 * @property list<int> $renewal_reminders
 * @property Carbon $created_at
 * @property-read Package $package
 * @property-read Programme $programme
 */
class LicencePool extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['package_id', 'programme_id', 'billing', 'term_months', 'unit_price_cents', 'seats', 'seats_next_month', 'starts_on', 'ends_on', 'cancel_at_period_end', 'status', 'renewal_reminders'];

    protected $attributes = ['renewal_reminders' => '[]'];

    protected function casts(): array
    {
        return [
            'term_months' => 'integer', 'unit_price_cents' => 'integer', 'seats' => 'integer', 'seats_next_month' => 'integer',
            'starts_on' => 'date', 'ends_on' => 'date', 'cancel_at_period_end' => 'boolean', 'renewal_reminders' => 'array',
        ];
    }

    /** @return BelongsTo<Package, $this> */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /** @return BelongsTo<Programme, $this> */
    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function isMonthly(): bool
    {
        return $this->billing === Package::MONTHLY;
    }
}
