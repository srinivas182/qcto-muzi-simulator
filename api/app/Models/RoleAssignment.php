<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $user_id
 * @property string $role_id
 * @property string|null $centre_id
 */
class RoleAssignment extends Pivot
{
    use HasUuids;

    protected $table = 'role_user';

    public $incrementing = false;

    protected $keyType = 'string';
}
