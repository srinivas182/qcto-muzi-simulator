<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The tenant (or platform) context of the current request or job.
 *
 * Every change is pushed to the PostgreSQL session (app.tenant_id / app.platform_access) so
 * row-level security enforces the same boundary as the application. Registered as a scoped
 * singleton, so it resets between Octane requests and queued jobs.
 */
final class TenantContext
{
    private ?Tenant $tenant = null;

    private bool $platform = false;

    /** HQ staff member viewing this tenant in a read-only support session. */
    private ?string $supportUserId = null;

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function tenantId(): ?string
    {
        return $this->tenant?->id;
    }

    public function hasTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    /** Act as a single tenant: only its rows are visible. */
    public function setTenant(Tenant $tenant): void
    {
        $this->tenant = $tenant;
        $this->platform = false;
        $this->apply();
    }

    /** Act as the platform (Super Admin): all tenants' rows are visible. */
    public function setPlatform(): void
    {
        $this->tenant = null;
        $this->platform = true;
        $this->apply();
    }

    /** No context: the database shows no tenant-owned rows at all. */
    public function clear(): void
    {
        $this->tenant = null;
        $this->platform = false;
        $this->supportUserId = null;
        $this->apply();
    }

    /** Lets the database show this HQ staff member's own user row inside the current tenant. */
    public function setSupportUser(?string $userId): void
    {
        $this->supportUserId = $userId;
        $this->apply();
    }

    public function supportUserId(): ?string
    {
        return $this->supportUserId;
    }

    /**
     * Run a callback in another context and restore the previous one afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAs(?Tenant $tenant, callable $callback, bool $platform = false): mixed
    {
        [$prevTenant, $prevPlatform] = [$this->tenant, $this->platform];

        $platform ? $this->setPlatform() : ($tenant ? $this->setTenant($tenant) : $this->clear());

        try {
            return $callback();
        } finally {
            $this->tenant = $prevTenant;
            $this->platform = $prevPlatform;
            $this->apply();
        }
    }

    private function apply(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select(
            "select set_config('app.tenant_id', ?, false), set_config('app.platform_access', ?, false), set_config('app.support_user_id', ?, false)",
            [$this->tenant->id ?? '', $this->platform ? 'on' : 'off', $this->supportUserId ?? ''],
        );
    }
}
