<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

/** HQ staff sign in on platform hosts only, and the two worlds never mix. */
final class PlatformAuthTest extends IdentityTestCase
{
    public function test_hq_staff_sign_in_on_the_admin_host(): void
    {
        $this->userIn(null, ['email' => 'srini@mayuraconsultancy.com', 'name' => 'Srini']);

        $token = $this->postJson($this->url('/platform/auth/login', 'admin.simuwork.test'), [
            'email' => 'srini@mayuraconsultancy.com', 'password' => 'correct-horse-battery',
        ])->assertOk()->assertJsonPath('user.is_platform_staff', true)->json('token');

        $this->getJson($this->url('/platform/auth/me', 'admin.simuwork.test'), $this->bearer($token))->assertOk();
    }

    public function test_provider_staff_cannot_sign_in_to_hq(): void
    {
        $this->userIn($this->ubuntu, ['email' => 'nomsa@ubuntuskills.co.za']);

        $this->postJson($this->url('/platform/auth/login', 'admin.simuwork.test'), [
            'email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery',
        ])->assertStatus(422);
    }

    public function test_hq_staff_cannot_sign_in_on_a_provider_address(): void
    {
        $this->userIn(null, ['email' => 'srini@mayuraconsultancy.com']);

        $this->postJson($this->url('/auth/login'), ['email' => 'srini@mayuraconsultancy.com', 'password' => 'correct-horse-battery'])
            ->assertStatus(422);
    }

    public function test_a_provider_token_is_refused_on_hq_routes(): void
    {
        $this->userIn($this->ubuntu, ['email' => 'nomsa@ubuntuskills.co.za']);
        $token = $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery'])->json('token');
        $this->resetAuthGuards();

        $this->getJson($this->url('/platform/auth/me', 'admin.simuwork.test'), $this->bearer($token))->assertUnauthorized();
    }

    public function test_hq_routes_do_not_exist_on_provider_addresses(): void
    {
        $this->postJson($this->url('/platform/auth/login'), ['email' => 'a@b.co', 'password' => 'x'])->assertNotFound();
    }

    public function test_mobile_number_sign_in_is_not_offered_for_hq(): void
    {
        $this->postJson($this->url('/platform/auth/otp/request', 'admin.simuwork.test'), ['phone' => '0821234567'])->assertNotFound();
    }
}
