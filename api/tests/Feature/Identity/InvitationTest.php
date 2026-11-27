<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Identity\InvitationService;
use App\Models\User;
use App\Models\UserConsent;
use App\Tenancy\TenantContext;

final class InvitationTest extends IdentityTestCase
{
    /** @return array{token: string, url: string} */
    private function invite(?string $phone = '0821234567', ?string $email = null): array
    {
        return app(TenantContext::class)->runAs($this->ubuntu, function () use ($phone, $email) {
            $result = app(InvitationService::class)->create($this->ubuntu, 'Thandiwe Mokoena', 'learner', $phone, $email);

            return ['token' => $result['token'], 'url' => $result['url']];
        });
    }

    public function test_learner_joins_by_link_with_a_code_and_consent(): void
    {
        $invite = $this->invite();
        $this->assertSame('https://ubuntu.simuwork.test/join/'.$invite['token'], $invite['url']);

        $this->getJson($this->url('/invitations/'.$invite['token']))
            ->assertOk()
            ->assertJsonPath('data.provider', 'Ubuntu Skills Academy')
            ->assertJsonPath('data.method', 'phone')
            ->assertJsonPath('data.sent_to', '+27 •• ••• 4567');

        $this->postJson($this->url('/invitations/'.$invite['token'].'/code'))->assertStatus(202);

        $this->postJson($this->url('/invitations/'.$invite['token'].'/accept'), [
            'code' => $this->lastCode('+27821234567'),
            'consents' => ['terms' => true, 'popia_processing' => true],
            'client' => 'mobile',
        ])
            ->assertCreated()
            ->assertJsonPath('user.name', 'Thandiwe Mokoena')
            ->assertJsonPath('user.consent_required', []);

        $user = app(TenantContext::class)->runAs($this->ubuntu, fn () => User::query()->where('phone', '+27821234567')->firstOrFail());
        $this->assertNotNull($user->phone_verified_at);
        $this->assertSame(2, app(TenantContext::class)->runAs($this->ubuntu, fn () => UserConsent::query()->where('user_id', $user->id)->count()));
    }

    public function test_consent_is_required_to_join(): void
    {
        $invite = $this->invite();
        $this->postJson($this->url('/invitations/'.$invite['token'].'/code'));

        $this->postJson($this->url('/invitations/'.$invite['token'].'/accept'), [
            'code' => $this->lastCode('+27821234567'),
            'consents' => ['terms' => true, 'popia_processing' => false],
        ])->assertStatus(422)->assertJsonValidationErrors('consents.popia_processing');
    }

    public function test_an_invitation_works_only_once(): void
    {
        $invite = $this->invite();
        $this->postJson($this->url('/invitations/'.$invite['token'].'/code'));
        $payload = ['code' => $this->lastCode('+27821234567'), 'consents' => ['terms' => true, 'popia_processing' => true]];

        $this->postJson($this->url('/invitations/'.$invite['token'].'/accept'), $payload)->assertCreated();
        $this->getJson($this->url('/invitations/'.$invite['token']))->assertStatus(422);
    }

    public function test_an_invitation_only_works_at_its_own_provider(): void
    {
        $invite = $this->invite();

        $this->getJson($this->url('/invitations/'.$invite['token'], 'lebone.simuwork.test'))->assertStatus(422);
    }

    public function test_staff_invited_by_email_set_a_password(): void
    {
        $invite = $this->invite(null, 'grace@ubuntuskills.co.za');

        $this->postJson($this->url('/invitations/'.$invite['token'].'/accept'), [
            'password' => 'short', 'consents' => ['terms' => true, 'popia_processing' => true],
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->postJson($this->url('/invitations/'.$invite['token'].'/accept'), [
            'password' => 'a-long-enough-password', 'consents' => ['terms' => true, 'popia_processing' => true],
        ])->assertCreated();

        $this->postJson($this->url('/auth/login'), ['email' => 'grace@ubuntuskills.co.za', 'password' => 'a-long-enough-password'])->assertOk();
    }

    public function test_expired_invitations_are_refused(): void
    {
        $invite = $this->invite();
        $this->travel(15)->days();

        $this->getJson($this->url('/invitations/'.$invite['token']))->assertStatus(422);
    }
}
