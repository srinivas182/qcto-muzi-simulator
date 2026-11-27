<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/** Devices, sign-out, POPIA consent gate and password reset. */
final class AccountTest extends IdentityTestCase
{
    private function login(string $device = 'Chrome on Windows'): string
    {
        return $this->postJson($this->url('/auth/login'), [
            'email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery', 'device_name' => $device,
        ])->json('token');
    }

    private User $staffUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staffUser = $this->userIn($this->ubuntu, ['email' => 'nomsa@ubuntuskills.co.za']);
    }

    public function test_people_see_and_sign_out_their_devices(): void
    {
        $laptop = $this->login('Chrome on Windows');
        $this->login('Galaxy A15');

        $sessions = $this->getJson($this->url('/auth/sessions'), $this->bearer($laptop))->assertOk()->json('data');
        $this->assertCount(2, $sessions);
        $phone = collect($sessions)->firstWhere('device', 'Galaxy A15');
        $this->assertFalse($phone['current']);

        $this->deleteJson($this->url('/auth/sessions/'.$phone['id']), [], $this->bearer($laptop))->assertOk();
        $this->assertCount(1, $this->getJson($this->url('/auth/sessions'), $this->bearer($laptop))->json('data'));
    }

    public function test_sign_out_revokes_the_current_token(): void
    {
        $token = $this->login();

        $this->postJson($this->url('/auth/logout'), [], $this->bearer($token))->assertOk();
        $this->resetAuthGuards();

        $this->getJson($this->url('/auth/me'), $this->bearer($token))->assertUnauthorized();
    }

    public function test_consent_gate_blocks_until_the_privacy_notice_is_accepted(): void
    {
        Route::middleware(['api', 'tenant', 'auth:sanctum', 'consented'])->get('/api/v1/_test/protected', fn () => ['ok' => true]);
        $token = $this->login();

        $this->getJson($this->url('/_test/protected'), $this->bearer($token))
            ->assertForbidden()
            ->assertJsonPath('code', 'CONSENT_REQUIRED')
            ->assertJsonPath('consent_required', ['terms', 'popia_processing']);

        $this->postJson($this->url('/auth/consents'), ['purpose' => 'terms', 'granted' => true], $this->bearer($token))->assertOk();
        $this->postJson($this->url('/auth/consents'), ['purpose' => 'popia_processing', 'granted' => true], $this->bearer($token))
            ->assertJsonPath('data.consent_required', []);

        $this->getJson($this->url('/_test/protected'), $this->bearer($token))->assertOk();
    }

    public function test_a_new_notice_version_asks_again(): void
    {
        $token = $this->login();
        foreach (['terms', 'popia_processing'] as $purpose) {
            $this->postJson($this->url('/auth/consents'), ['purpose' => $purpose, 'granted' => true], $this->bearer($token));
        }

        config(['identity.consents.popia_processing' => '2027-03']);

        $this->getJson($this->url('/auth/me'), $this->bearer($token))->assertJsonPath('data.consent_required', ['popia_processing']);
    }

    public function test_password_reset_by_email_signs_out_everywhere(): void
    {
        Notification::fake();
        $old = $this->login();

        $this->postJson($this->url('/auth/password/forgot'), ['email' => 'nomsa@ubuntuskills.co.za'])->assertStatus(202);

        Notification::assertSentTimes(ResetPasswordLink::class, 1);
        $url = Notification::sent($this->staffUser, ResetPasswordLink::class)->first()->url;
        $this->assertStringStartsWith('http://ubuntu.simuwork.test/reset-password?token=', $url);
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        $this->postJson($this->url('/auth/password/reset'), [
            'token' => $query['token'], 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->resetAuthGuards();
        $this->getJson($this->url('/auth/me'), $this->bearer($old))->assertUnauthorized();
        $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'brand-new-password'])->assertOk();

        $this->postJson($this->url('/auth/password/reset'), [
            'token' => $query['token'], 'password' => 'another-password-1', 'password_confirmation' => 'another-password-1',
        ])->assertStatus(422);
    }

    public function test_password_reset_never_reveals_whether_an_email_exists(): void
    {
        Notification::fake();

        $this->postJson($this->url('/auth/password/forgot'), ['email' => 'nobody@example.com'])->assertStatus(202);

        Notification::assertNothingSent();
    }
}
