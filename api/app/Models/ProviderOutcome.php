<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An outcome a provider adds on top of HQ's programme (code like TS-1.P1). Visible only to that provider.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $programme_id
 * @property int $duty_area_number
 * @property string $code
 * @property string $statement
 * @property string|null $plain_words
 * @property string|null $created_by
 */
class ProviderOutcome extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = ['programme_id', 'duty_area_number', 'code', 'statement', 'plain_words', 'created_by'];

    protected function casts(): array
    {
        return ['duty_area_number' => 'integer'];
    }
}
