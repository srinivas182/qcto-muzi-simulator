<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Audit\AuditLogger;
use App\Models\Role;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Custom roles and role assignment, with guard rails:
 *  - system roles cannot be edited (clone them instead)
 *  - nobody can grant a permission they do not hold themselves (no privilege escalation)
 *  - permissions must belong to the right scope (HQ vs provider)
 *  - a provider always keeps at least one TP Administrator
 */
final class RoleService
{
    /**
     * Permissions that describe what a person does rather than an admin power. Anyone allowed to
     * manage people may hand them out without holding them (e.g. an admin enrols learners).
     */
    private const FUNCTIONAL_PERMISSIONS = ['learning.use', 'review_pack.view', 'attendance.confirm_workplace'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function scope(): string
    {
        return $this->context->isPlatform() ? Role::SCOPE_PLATFORM : Role::SCOPE_TENANT;
    }

    /** @param list<string> $permissions */
    public function create(User $actor, string $name, ?string $description, array $permissions): Role
    {
        $permissions = $this->validatePermissions($actor, $permissions);
        $key = Str::slug($name, '_');

        if (Role::query()->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
        }

        $role = Role::query()->create([
            'tenant_id' => $this->context->tenantId(),
            'key' => $key,
            'name' => $name,
            'description' => $description,
            'scope' => $this->scope(),
            'is_system' => false,
            'permissions' => $permissions,
        ]);

        $this->audit->record('roles.created', $role, ['after' => ['name' => $name, 'permissions' => $permissions]]);

        return $role;
    }

    /** @param list<string> $permissions */
    public function update(User $actor, Role $role, string $name, ?string $description, array $permissions): Role
    {
        $this->assertEditable($role);
        $permissions = $this->validatePermissions($actor, $permissions);
        $before = ['name' => $role->name, 'permissions' => $role->permissions];

        $role->update(['name' => $name, 'description' => $description, 'permissions' => $permissions]);
        $this->audit->record('roles.updated', $role, ['before' => $before, 'after' => ['name' => $name, 'permissions' => $permissions]]);

        return $role;
    }

    public function delete(Role $role): void
    {
        $this->assertEditable($role);

        if (DB::table('role_user')->where('role_id', $role->id)->exists()) {
            throw ValidationException::withMessages(['role' => 'Move people to another role before deleting this one.']);
        }

        $this->audit->record('roles.deleted', $role, ['before' => ['name' => $role->name, 'permissions' => $role->permissions]]);
        $role->delete();
    }

    /**
     * Replace a person's roles.
     *
     * @param  list<array{role_id: string, centre_id?: string|null}>  $assignments
     */
    public function assign(User $actor, User $user, array $assignments): void
    {
        $roles = [];
        foreach ($assignments as $assignment) {
            $role = Role::query()->find($assignment['role_id']);
            if ($role === null) {
                throw ValidationException::withMessages(['roles' => 'Unknown role.']);
            }
            $this->assertAssignable($actor, $role);
            $roles[] = [$role, $assignment['centre_id'] ?? null];
        }

        $before = $user->roles()->pluck('key')->all();
        $hadAdmin = ! $this->context->isPlatform() && $this->tenantHasAdmin();

        DB::transaction(function () use ($actor, $user, $roles, $hadAdmin): void {
            $user->roles()->detach();
            foreach ($roles as [$role, $centreId]) {
                $user->roles()->attach($role->id, ['tenant_id' => $user->tenant_id, 'centre_id' => $centreId, 'assigned_by' => $actor->id]);
            }

            if ($hadAdmin && ! $this->tenantHasAdmin()) {
                throw ValidationException::withMessages(['roles' => 'At least one person must remain a TP Administrator.']);
            }
        });

        $user->forgetPermissionCache();
        $after = array_map(fn (array $r) => $r[0]->key, $roles);
        $this->audit->record('roles.assigned', $user, ['before' => $before, 'after' => $after]);
    }

    /** Assign a role by key (used when an invitation is accepted). */
    public function assignKey(User $user, string $key): void
    {
        $role = Role::query()->where('key', $key)->first();
        if ($role === null) {
            return;
        }

        $user->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => $user->tenant_id]]);
        $user->forgetPermissionCache();
    }

    /**
     * What someone with this role would see: permissions in plain language and their menu.
     *
     * @return array{role: string, permissions: list<string>, navigation: list<array{id: string, section: string, label: string, icon: string, path: string}>}
     */
    public function preview(Role $role): array
    {
        $permissions = $role->effectivePermissions();
        $area = $role->scope === Role::SCOPE_PLATFORM ? 'platform' : 'tenant';

        return [
            'role' => $role->name,
            'permissions' => PermissionRegistry::labels($permissions),
            'navigation' => Navigation::for($area, $permissions),
        ];
    }

    /** @param list<string> $permissions
     * @return list<string> */
    private function validatePermissions(User $actor, array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));

        if ($permissions === []) {
            throw ValidationException::withMessages(['permissions' => 'Choose at least one permission.']);
        }

        foreach ($permissions as $permission) {
            if (PermissionRegistry::scopeOf($permission) !== $this->scope()) {
                throw ValidationException::withMessages(['permissions' => "“{$permission}” cannot be used here."]);
            }
            if (! $actor->hasPermission($permission) && ! in_array($permission, self::FUNCTIONAL_PERMISSIONS, true)) {
                throw ValidationException::withMessages(['permissions' => 'You cannot give a permission you do not have yourself.']);
            }
        }

        return $permissions;
    }

    private function assertEditable(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'Built-in roles cannot be changed. Create a custom role instead.']);
        }
    }

    private function assertAssignable(User $actor, Role $role): void
    {
        $allowedScopes = $this->context->isPlatform() ? [Role::SCOPE_PLATFORM] : [Role::SCOPE_TENANT, Role::SCOPE_EXTERNAL];

        if (! in_array($role->scope, $allowedScopes, true)) {
            throw ValidationException::withMessages(['roles' => "“{$role->name}” cannot be given here."]);
        }

        foreach ($role->effectivePermissions() as $permission) {
            if (! $actor->hasPermission($permission) && ! in_array($permission, self::FUNCTIONAL_PERMISSIONS, true)) {
                throw ValidationException::withMessages(['roles' => "You cannot give the role “{$role->name}” because it has permissions you do not have."]);
            }
        }
    }

    private function tenantHasAdmin(): bool
    {
        $adminRoleId = Role::query()->whereNull('tenant_id')->where('key', 'tp_admin')->value('id');

        // Row-level security limits this to the current provider's assignments.
        return DB::table('role_user')->where('role_id', $adminRoleId)->exists();
    }
}
