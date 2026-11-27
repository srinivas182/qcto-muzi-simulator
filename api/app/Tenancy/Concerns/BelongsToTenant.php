<?php

declare(strict_types=1);

namespace App\Tenancy\Concerns;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For tenant-owned models. Application-level isolation (global scope + automatic tenant_id),
 * on top of PostgreSQL row-level security.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $context = app(TenantContext::class);
            $tenantId = $context->tenantId();

            // Platform-owned rows (e.g. HQ staff) may have no tenant, but only in platform context.
            if ($tenantId === null && $context->isPlatform() && static::allowsPlatformRows()) {
                return;
            }

            if ($tenantId === null) {
                throw new LogicException(sprintf('Cannot create %s without a tenant context.', $model::class));
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    /** Override to true on models that may also hold platform-level rows (tenant_id NULL). */
    public static function allowsPlatformRows(): bool
    {
        return false;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
