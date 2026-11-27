<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Identity\ConsentService;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Tests\Feature\Identity\IdentityTestCase;

abstract class AccessTestCase extends IdentityTestCase
{
    protected const HQ = 'admin.simuwork.test';

    /**
     * A person with a role and a bearer token (consents given, as after onboarding).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: User, 1: string}
     */
    protected function person(?Tenant $tenant, string $roleKey, array $attributes = []): array
    {
        $user = $this->userIn($tenant, $attributes);
        $context = app(TenantContext::class);

        $context->runAs($tenant, function () use ($user, $roleKey, $tenant): void {
            $role = Role::query()->where('key', $roleKey)->firstOrFail();
            $user->roles()->attach($role->id, ['tenant_id' => $tenant?->id]);

            if ($tenant !== null) {
                foreach (array_keys(app(ConsentService::class)->current()) as $purpose) {
                    app(ConsentService::class)->record($user, $purpose, true);
                }
            }
        }, platform: $tenant === null);
        $context->clear();

        return [$user, $user->createToken('test device')->plainTextToken];
    }

    protected function roleId(string $key): string
    {
        return (string) app(TenantContext::class)->runAs(null, fn () => Role::query()->where('key', $key)->value('id'), platform: true);
    }

    /** @param array<string, mixed> $attributes */
    protected function assertAudited(string $action, array $attributes = []): void
    {
        $found = app(TenantContext::class)->runAs(null, fn () => AuditLog::query()
            ->where('action', $action)->where($attributes)->exists(), platform: true);
        app(TenantContext::class)->clear();

        $this->assertTrue($found, "No audit entry {$action}");
    }

    /** Make the next request authenticate afresh. */
    protected function fresh(): static
    {
        $this->resetAuthGuards();
        app(TenantContext::class)->clear();

        return $this;
    }
}
