<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\CentreFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A training centre / campus of a provider. Used later for geofenced attendance.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $code
 * @property int $geofence_radius_m
 */
class Centre extends Model
{
    /** @use HasFactory<CentreFactory> */
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['name', 'code', 'address', 'latitude', 'longitude', 'geofence_radius_m'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'geofence_radius_m' => 'integer',
        ];
    }
}
