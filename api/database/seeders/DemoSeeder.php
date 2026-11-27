<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Identity\ConsentService;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\DomainService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo data for local development and prospect demos (never production):
 *   php artisan db:seed --class=DemoSeeder
 * One provider (ubuntu.<platform domain>) with a person for each role, and HQ staff.
 * Every account's password is "demo-password-2026".
 */
final class DemoSeeder extends Seeder
{
    public const PASSWORD = 'demo-password-2026';

    public function run(TenantContext $context, DomainService $domains, ConsentService $consents): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $context->runAs(null, function () use ($context, $domains, $consents): void {
            $tenant = Tenant::query()->firstOrCreate(['slug' => 'ubuntu'], ['name' => 'Ubuntu Skills Academy', 'status' => 'active']);
            if (! $tenant->domains()->exists()) {
                $domains->createSubdomain($tenant);
            }

            $people = [
                'tp_admin' => ['Nomsa Dlamini', 'admin@ubuntu.demo'],
                'centre_manager' => ['Pieter van Wyk', 'centre@ubuntu.demo'],
                'facilitator' => ['Lerato Mokoena', 'facilitator@ubuntu.demo'],
                'assessor' => ['Sibusiso Nkosi', 'assessor@ubuntu.demo'],
                'moderator' => ['Ayesha Patel', 'moderator@ubuntu.demo'],
                'learner' => ['Thandiwe Khumalo', 'learner@ubuntu.demo'],
                'qcto_reviewer' => ['Johan Botha', 'qcto@ubuntu.demo'],
            ];

            $context->runAs($tenant, function () use ($tenant, $people, $consents): void {
                foreach ($people as $roleKey => [$name, $email]) {
                    $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => self::PASSWORD, 'email_verified_at' => now()]);
                    $role = Role::query()->where('key', $roleKey)->firstOrFail();
                    $user->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => $tenant->id]]);
                    foreach (array_keys($consents->current()) as $purpose) {
                        $consents->record($user, $purpose, true);
                    }
                }
            });

            $hq = [
                'super_admin' => ['Srini (HQ)', 'srini@hq.demo'],
                'support_finance' => ['Thabo Molefe', 'support@hq.demo'],
                'content_manager' => ['Zanele Mthembu', 'content@hq.demo'],
            ];
            foreach ($hq as $roleKey => [$name, $email]) {
                $user = User::query()->whereNull('tenant_id')->firstOrCreate(['email' => $email, 'tenant_id' => null], ['name' => $name, 'password' => self::PASSWORD, 'email_verified_at' => now()]);
                $role = Role::query()->whereNull('tenant_id')->where('key', $roleKey)->firstOrFail();
                $user->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => null]]);
            }
        }, platform: true);

        $this->command?->info('Demo ready. Password for every account: '.self::PASSWORD);
    }
}
