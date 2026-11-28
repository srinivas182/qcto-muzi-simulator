<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Tenancy\Dns\DnsResolver;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds and verifies tenant domains.
 *
 * A custom domain is usable only after the owner publishes a TXT record
 * `_simuwork-verify.<host>` containing the verification token. Until then it does not
 * resolve and no HTTPS certificate will be issued for it.
 */
final class DomainService
{
    public function __construct(private readonly DnsResolver $dns) {}

    /** The host links in emails and SMS should use: a verified primary or custom domain, else the subdomain. */
    public static function primaryHost(Tenant $tenant): string
    {
        $host = $tenant->domains()->whereNotNull('verified_at')->orderByDesc('is_primary')
            ->orderByRaw('case when type = ? then 0 else 1 end', [TenantDomain::TYPE_CUSTOM])
            ->value('host');

        return (string) ($host ?? $tenant->slug.'.'.config('tenancy.platform_domain'));
    }

    public function createSubdomain(Tenant $tenant): TenantDomain
    {
        $host = $tenant->slug.'.'.TenantResolver::platformDomain();

        /** @var TenantDomain $domain */
        $domain = $tenant->domains()->firstOrCreate(
            ['host' => $host],
            ['type' => TenantDomain::TYPE_SUBDOMAIN, 'is_primary' => ! $tenant->domains()->exists(), 'verified_at' => now()],
        );

        return $domain;
    }

    public function addCustomDomain(Tenant $tenant, string $host): TenantDomain
    {
        $host = TenantResolver::normaliseHost($host);
        $this->assertValidCustomHost($host);

        /** @var TenantDomain $domain */
        $domain = $tenant->domains()->create([
            'host' => $host,
            'type' => TenantDomain::TYPE_CUSTOM,
            'verification_token' => 'simuwork-'.Str::random(32),
        ]);

        return $domain;
    }

    /** Checks DNS and marks the domain verified when the TXT record matches. */
    public function verify(TenantDomain $domain): bool
    {
        if ($domain->type !== TenantDomain::TYPE_CUSTOM || $domain->isVerified()) {
            return $domain->isVerified();
        }

        $record = config('tenancy.verification_record_prefix').'.'.$domain->host;
        $found = in_array($domain->verification_token, $this->dns->txt($record), true);

        $domain->forceFill([
            'last_checked_at' => now(),
            'verified_at' => $found ? now() : null,
        ])->save();

        TenantResolver::forgetHost($domain->host);

        return $found;
    }

    /**
     * Instructions shown to the provider for proving domain ownership.
     *
     * @return array{type: string, name: string, value: string}
     */
    public function verificationRecord(TenantDomain $domain): array
    {
        return [
            'type' => 'TXT',
            'name' => config('tenancy.verification_record_prefix').'.'.$domain->host,
            'value' => (string) $domain->verification_token,
        ];
    }

    private function assertValidCustomHost(string $host): void
    {
        $platform = TenantResolver::platformDomain();
        $valid = (bool) preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host);

        $error = match (true) {
            ! $valid => 'Enter a valid domain such as learn.example.co.za.',
            $host === $platform || str_ends_with($host, '.'.$platform) => 'Platform sub-domains are assigned automatically.',
            TenantDomain::query()->where('host', $host)->exists() => 'This domain is already connected to an account.',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['host' => $error]);
        }
    }
}
