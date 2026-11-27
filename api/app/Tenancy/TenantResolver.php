<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Works out which training provider a request belongs to:
 *  1. <slug>.<platform domain>      → tenant sub-domain
 *  2. a verified custom domain       → that tenant
 *  3. platform hosts + X-Tenant header (mobile app, API host) → tenant by slug
 * Unverified custom domains, reserved sub-domains and archived tenants never resolve.
 */
final class TenantResolver
{
    private const CACHE_SECONDS = 60;

    public function resolve(Request $request): ?Tenant
    {
        $host = self::normaliseHost($request->getHost());

        if ($this->isPlatformHost($host)) {
            $slug = $request->headers->get((string) config('tenancy.header'));

            return is_string($slug) && $slug !== '' ? $this->bySlug(strtolower($slug)) : null;
        }

        return $this->byHost($host);
    }

    public function byHost(string $host): ?Tenant
    {
        $host = self::normaliseHost($host);

        $tenantId = Cache::remember('tenancy:host:'.$host, self::CACHE_SECONDS, function () use ($host): string {
            $domain = TenantDomain::query()->where('host', $host)->first();

            if ($domain === null || ($domain->type === TenantDomain::TYPE_CUSTOM && ! $domain->isVerified())) {
                return '';
            }

            return $domain->tenant_id;
        });

        return $tenantId === '' ? null : $this->reachable(Tenant::query()->find($tenantId));
    }

    public function bySlug(string $slug): ?Tenant
    {
        if (in_array($slug, self::reservedSubdomains(), true)) {
            return null;
        }

        return $this->reachable(Tenant::query()->where('slug', $slug)->first());
    }

    /** The bare platform domain and its reserved sub-domains (admin., api., www., …). */
    public function isPlatformHost(string $host): bool
    {
        $platform = self::platformDomain();

        if ($host === $platform) {
            return true;
        }

        if (! str_ends_with($host, '.'.$platform)) {
            return false;
        }

        $sub = substr($host, 0, -strlen('.'.$platform));

        return in_array($sub, self::reservedSubdomains(), true);
    }

    public static function forgetHost(string $host): void
    {
        Cache::forget('tenancy:host:'.self::normaliseHost($host));
    }

    public static function normaliseHost(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }

    public static function platformDomain(): string
    {
        return self::normaliseHost((string) config('tenancy.platform_domain'));
    }

    /** @return list<string> */
    public static function reservedSubdomains(): array
    {
        /** @var list<string> $reserved */
        $reserved = config('tenancy.reserved_subdomains', []);

        return $reserved;
    }

    private function reachable(?Tenant $tenant): ?Tenant
    {
        return $tenant !== null && $tenant->isReachable() ? $tenant : null;
    }
}
