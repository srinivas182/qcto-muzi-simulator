<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A price band: from this many seats, each seat costs this much (cents, excluding VAT).
 *
 * @property string $id
 * @property string $package_id
 * @property int $from_seats
 * @property int $unit_price_cents
 */
class PackagePrice extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['package_id', 'from_seats', 'unit_price_cents'];

    protected function casts(): array
    {
        return ['from_seats' => 'integer', 'unit_price_cents' => 'integer'];
    }
}
