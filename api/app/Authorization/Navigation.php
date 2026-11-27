<?php

declare(strict_types=1);

namespace App\Authorization;

/** The menu a set of permissions unlocks (config/navigation.php). */
final class Navigation
{
    /**
     * @param  list<string>  $permissions
     * @return list<array{id: string, section: string, label: string, icon: string, path: string}>
     */
    public static function for(string $area, array $permissions): array
    {
        /** @var list<array{id: string, section: string, label: string, icon: string, path: string, permission: string}> $items */
        $items = config("navigation.{$area}", []);
        $out = [];

        foreach ($items as $item) {
            if (in_array($item['permission'], $permissions, true)) {
                unset($item['permission']);
                $out[] = $item;
            }
        }

        return $out;
    }
}
