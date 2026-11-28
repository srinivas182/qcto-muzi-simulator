<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seats a provider bought: a new pool or a top-up of an existing one.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $package_id
 * @property string $licence_pool_id
 * @property string $kind
 * @property int $seats
 * @property int $unit_price_cents
 * @property array<string, mixed> $pricing
 * @property string $status
 * @property string|null $ordered_by
 * @property-read LicencePool $pool
 */
class Order extends Model
{
    use BelongsToTenant, HasUuids;

    public const NEW = 'new';

    public const TOP_UP = 'top_up';

    protected $fillable = ['package_id', 'licence_pool_id', 'kind', 'seats', 'unit_price_cents', 'pricing', 'status', 'ordered_by'];

    protected function casts(): array
    {
        return ['seats' => 'integer', 'unit_price_cents' => 'integer', 'pricing' => 'array'];
    }

    /** @return BelongsTo<LicencePool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(LicencePool::class, 'licence_pool_id');
    }
}
