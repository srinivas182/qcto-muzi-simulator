<?php

declare(strict_types=1);

namespace App\Models;

use App\Authorization\RoleRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A system role (tenant_id NULL, defined in config/roles.php) or a provider's custom role.
 * Inside a tenant, shared system roles and that tenant's own custom roles are visible.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $scope
 * @property bool $is_system
 * @property list<string> $permissions
 */
class Role extends Model
{
    use HasUuids;

    public const SCOPE_PLATFORM = 'platform';

    public const SCOPE_TENANT = 'tenant';

    public const SCOPE_EXTERNAL = 'external';

    protected $fillable = ['key', 'name', 'description', 'scope', 'is_system', 'permissions', 'tenant_id'];

    protected $attributes = ['permissions' => '[]', 'is_system' => false];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('visible', function (Builder $query): void {
            $context = app(TenantContext::class);
            $column = $query->getModel()->qualifyColumn('tenant_id');
            if ($context->isPlatform()) {
                // HQ manages its own roles only; providers' custom roles stay with the provider.
                $query->whereNull($column);

                return;
            }
            $query->where(fn (Builder $q) => $q->whereNull($column)->when(
                $context->tenantId(),
                fn (Builder $q, string $id) => $q->orWhere($column, $id),
            ));
        });
    }

    /** @return list<string> */
    public function effectivePermissions(): array
    {
        return $this->is_system ? RoleRegistry::permissionsFor($this->key) : $this->permissions;
    }

    /** @return BelongsToMany<User, $this, RoleAssignment> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(RoleAssignment::class)->withPivot(['id', 'centre_id', 'tenant_id'])->withTimestamps();
    }
}
