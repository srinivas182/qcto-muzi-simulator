<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A small photo taken during a camera-checked test (Sprint 5.1c). Private storage only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $test_sitting_id
 * @property string $path
 * @property int $bytes
 * @property Carbon $taken_at
 * @property Carbon|null $delete_after
 */
class SittingPhoto extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['test_sitting_id', 'path', 'bytes', 'taken_at', 'delete_after'];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'delete_after' => 'datetime', 'bytes' => 'integer'];
    }
}
