<?php

declare(strict_types=1);

namespace App\Branding;

use App\Audit\AuditLogger;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

/**
 * A provider's look: name, logo, colours and default language.
 * Branding never reaches platform-owned UI (the locked MCS footer) and colours must stay readable:
 * white text on the primary and menu colours must pass WCAG AA (contrast 4.5:1).
 */
final class BrandingService
{
    public const DEFAULTS = [
        'display_name' => null,
        'logo_url' => null,
        'primary' => '#4B45D6',
        'accent' => '#C9A24B',
        'nav' => '#0D1230',
        'default_locale' => 'en',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{display_name: string, logo_url: string|null, primary: string, accent: string, nav: string, default_locale: string} */
    public function present(Tenant $tenant): array
    {
        /** @var array<string, mixed> $stored */
        $stored = $tenant->branding ?? [];
        $branding = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));

        return [
            'display_name' => is_string($branding['display_name']) && $branding['display_name'] !== '' ? $branding['display_name'] : $tenant->name,
            'logo_url' => is_string($branding['logo_url']) ? $branding['logo_url'] : null,
            'primary' => (string) $branding['primary'],
            'accent' => (string) $branding['accent'],
            'nav' => (string) $branding['nav'],
            'default_locale' => (string) $branding['default_locale'],
        ];
    }

    /** @param array<string, mixed> $changes already shape-validated by the controller */
    public function update(Tenant $tenant, array $changes): Tenant
    {
        $changes = array_intersect_key($changes, self::DEFAULTS);

        foreach (['primary', 'accent', 'nav'] as $key) {
            if (isset($changes[$key]) && is_string($changes[$key])) {
                $changes[$key] = strtoupper($changes[$key]);
            }
        }
        foreach (['primary' => 'buttons', 'nav' => 'the menu'] as $key => $where) {
            if (isset($changes[$key]) && is_string($changes[$key]) && self::contrastWithWhite($changes[$key]) < 4.5) {
                throw ValidationException::withMessages([$key => "This colour is too light for white text on {$where}. Choose a darker shade so everyone can read it."]);
            }
        }

        $before = $this->present($tenant);
        /** @var array<string, mixed> $stored */
        $stored = $tenant->branding ?? [];
        $tenant->branding = array_merge($stored, $changes);
        $tenant->save();

        $this->audit->record('branding.updated', $tenant, ['before' => $before, 'after' => $this->present($tenant)]);

        return $tenant;
    }

    /** WCAG 2 contrast ratio between a hex colour and white. */
    public static function contrastWithWhite(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];

        return 1.05 / ($luminance + 0.05);
    }
}
