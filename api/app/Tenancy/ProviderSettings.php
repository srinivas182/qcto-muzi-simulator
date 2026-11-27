<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Audit\AuditLogger;
use App\Models\Tenant;
use App\Models\User;

/** HQ switches per training provider (stored in tenants.settings), each change audited for that provider. */
final class ProviderSettings
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function setProgrammeAdditions(Tenant $tenant, bool $enabled, User $actor): void
    {
        $before = $tenant->allowsProgrammeAdditions();
        if ($before === $enabled) {
            return;
        }

        $tenant->settings = ['programme_additions' => $enabled] + $tenant->settings;
        $tenant->save();
        // Recorded under the provider, so it also appears in that provider's own audit log.
        $this->context->runAs($tenant, fn () => $this->audit->record('providers.programme_additions_changed', $tenant, ['before' => $before, 'after' => $enabled], [], $actor));
    }
}
