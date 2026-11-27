<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filters tenant-owned models to the current tenant. In platform context nothing is filtered;
 * with no context, nothing matches (fail closed, mirroring the database policy).
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->isPlatform()) {
            return;
        }

        $tenantId = $context->tenantId();

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $supportUserId = $context->supportUserId();

        // During an HQ support session, the staff member's own account is visible too (matches RLS).
        if ($supportUserId !== null && $model instanceof User) {
            $builder->where(fn (Builder $q) => $q->where($model->qualifyColumn('tenant_id'), $tenantId)
                ->orWhere($model->qualifyColumn('id'), $supportUserId));

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
