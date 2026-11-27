<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evidence a provider asks for on top of HQ's programme. Visible only to that provider.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $programme_id
 * @property int $duty_area_number
 * @property string $description
 * @property string $type
 * @property int|null $min_count
 * @property string|null $created_by
 */
class ProviderEvidenceRequirement extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = ['programme_id', 'duty_area_number', 'description', 'type', 'min_count', 'created_by'];

    protected function casts(): array
    {
        return ['duty_area_number' => 'integer', 'min_count' => 'integer'];
    }
}
