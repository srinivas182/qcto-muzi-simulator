<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Tenancy\TenantContext;

final class CommandsTest extends IdentityTestCase
{
    public function test_invite_command_prints_a_join_link(): void
    {
        $this->artisan('user:invite', ['tenant' => 'ubuntu', 'name' => 'Sipho Dlamini', '--phone' => '0831234567'])
            ->expectsOutputToContain('https://ubuntu.simuwork.test/join/')
            ->assertSuccessful();
    }

    public function test_invite_command_rejects_missing_contact_details(): void
    {
        $this->artisan('user:invite', ['tenant' => 'ubuntu', 'name' => 'Nobody'])->assertFailed();
    }

    public function test_platform_staff_command_creates_an_hq_account_once(): void
    {
        $this->artisan('platform:staff-create', ['name' => 'Srini', 'email' => 'srini@mayuraconsultancy.com'])
            ->expectsOutputToContain('Temporary password')
            ->assertSuccessful();

        $exists = app(TenantContext::class)->runAs(null, fn () => User::query()->whereNull('tenant_id')->where('email', 'srini@mayuraconsultancy.com')->exists(), platform: true);
        $this->assertTrue($exists);

        $this->artisan('platform:staff-create', ['name' => 'Srini', 'email' => 'srini@mayuraconsultancy.com'])->assertFailed();
    }
}
