<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $staff_user_id
 * @property string $staff_name
 * @property string $reason
 * @property int|null $token_id
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $ended_by
 */
class SupportSession extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['staff_user_id', 'staff_name', 'reason', 'token_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
