<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Models\Role;
use App\Tenancy\TenantContext;

/** The 12 system roles defined in config/roles.php. */
final class RoleRegistry
{
    /** @return array<string, array{name: string, scope: string, description: string, permissions: list<string>, exclude?: list<string>}> */
    public static function definitions(): array
    {
        /** @var array<string, array{name: string, scope: string, description: string, permissions: list<string>, exclude?: list<string>}> $roles */
        $roles = config('roles', []);

        return $roles;
    }

    /** @return list<string> */
    public static function permissionsFor(string $key): array
    {
        $definition = self::definitions()[$key] ?? null;
        if ($definition === null) {
            return [];
        }

        $permissions = [];
        foreach ($definition['permissions'] as $permission) {
            $permissions = [...$permissions, ...match ($permission) {
                '*platform' => PermissionRegistry::keys('platform'),
                '*tenant' => PermissionRegistry::keys('tenant'),
                default => [$permission],
            }];
        }

        return array_values(array_diff(array_unique($permissions), $definition['exclude'] ?? []));
    }

    /** Creates or updates the shared system role rows. Safe to run repeatedly (migrations, deploys). */
    public static function syncSystemRoles(): void
    {
        app(TenantContext::class)->runAs(null, function (): void {
            foreach (self::definitions() as $key => $definition) {
                Role::query()->whereNull('tenant_id')->updateOrCreate(
                    ['key' => $key, 'tenant_id' => null],
                    ['name' => $definition['name'], 'description' => $definition['description'], 'scope' => $definition['scope'], 'is_system' => true],
                );
            }
        }, platform: true);
    }
}
