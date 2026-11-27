<?php

declare(strict_types=1);

namespace App\Authorization;

/** Reads the permission catalogue (config/permissions.php). */
final class PermissionRegistry
{
    /** @return array<string, array{scope: string, label: string, group: string}> */
    public static function all(): array
    {
        $out = [];
        /** @var array<string, array<string, array{scope: string, label: string}>> $groups */
        $groups = config('permissions.groups', []);

        foreach ($groups as $group => $permissions) {
            foreach ($permissions as $key => $definition) {
                $out[$key] = $definition + ['group' => $group];
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function keys(string $scope): array
    {
        return array_keys(array_filter(self::all(), fn (array $p) => $p['scope'] === $scope));
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function scopeOf(string $key): ?string
    {
        return self::all()[$key]['scope'] ?? null;
    }

    /**
     * Grouped, labelled catalogue for building custom roles.
     *
     * @return list<array{group: string, permissions: list<array{key: string, label: string}>}>
     */
    public static function catalogue(string $scope): array
    {
        $groups = [];
        foreach (self::all() as $key => $p) {
            if ($p['scope'] === $scope) {
                $groups[$p['group']][] = ['key' => $key, 'label' => $p['label']];
            }
        }

        return array_map(fn ($group, $perms) => ['group' => $group, 'permissions' => $perms], array_keys($groups), array_values($groups));
    }

    /** @param list<string> $keys
     * @return list<string> */
    public static function labels(array $keys): array
    {
        $all = self::all();

        return array_map(fn (string $k) => $all[$k]['label'] ?? $k, $keys);
    }
}
