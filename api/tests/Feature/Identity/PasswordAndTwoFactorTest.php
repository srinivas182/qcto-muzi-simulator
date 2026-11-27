<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Identity\Totp;
use App\Models\User;

final class PasswordAndTwoFactorTest extends IdentityTestCase
{
    private function staff(): User
    {
        return $this->userIn($this->ubuntu, ['email' => 'nomsa@ubuntuskills.co.za', 'name' => 'Nomsa Khumalo']);
    }

    public function test_staff_sign_in_with_email_and_password(): void
    {
        $this->staff();

        $this->postJson($this->url('/auth/login'), ['email' => 'Nomsa@UbuntuSkills.co.za', 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Nomsa Khumalo')
            ->assertJsonStructure(['token', 'expires_at']);
    }

    public function test_wrong_password_is_rejected_without_revealing_which_part_was_wrong(): void
    {
        $this->staff();

        $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'The email or password is incorrect.');
    }

    public function test_repeated_failures_are_slowed_down(): void
    {
        $this->staff();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'nope'])->assertStatus(422);
        }

        $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery'])
            ->assertStatus(429);
    }

    public function test_disabled_accounts_cannot_sign_in(): void
    {
        $this->userIn($this->ubuntu, ['email' => 'gone@ubuntuskills.co.za', 'status' => User::STATUS_DISABLED]);

        $this->postJson($this->url('/auth/login'), ['email' => 'gone@ubuntuskills.co.za', 'password' => 'correct-horse-battery'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('account');
    }

    public function test_full_two_factor_flow_with_authenticator_and_recovery_code(): void
    {
        $this->staff();
        $token = $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery'])->json('token');

        $secret = $this->postJson($this->url('/auth/two-factor'), [], $this->bearer($token))
            ->assertOk()
            ->assertJsonPath('data.otpauth_uri', fn (string $uri) => str_starts_with($uri, 'otpauth://totp/'))
            ->json('data.secret');

        $this->postJson($this->url('/auth/two-factor/confirm'), ['code' => '000000'], $this->bearer($token))->assertStatus(422);

        $recovery = $this->postJson($this->url('/auth/two-factor/confirm'), ['code' => Totp::code($secret)], $this->bearer($token))
            ->assertOk()
            ->json('data.recovery_codes');
        $this->assertCount(8, $recovery);

        // Next sign-in needs the second factor.
        $challenge = $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery'])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonMissingPath('token')
            ->json('challenge_token');

        $this->postJson($this->url('/auth/two-factor/challenge'), ['challenge_token' => $challenge, 'code' => '000000'])->assertStatus(422);
        $this->postJson($this->url('/auth/two-factor/challenge'), ['challenge_token' => $challenge, 'code' => Totp::code($secret)])
            ->assertOk()
            ->assertJsonPath('user.two_factor_enabled', true);

        // A recovery code works once.
        $challenge2 = $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery'])->json('challenge_token');
        $this->postJson($this->url('/auth/two-factor/challenge'), ['challenge_token' => $challenge2, 'recovery_code' => $recovery[0]])->assertOk();

        $challenge3 = $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery'])->json('challenge_token');
        $this->postJson($this->url('/auth/two-factor/challenge'), ['challenge_token' => $challenge3, 'recovery_code' => $recovery[0]])->assertStatus(422);
    }

    public function test_totp_matches_the_rfc_6238_reference_vector(): void
    {
        // RFC 6238 SHA-1 secret "12345678901234567890" at T=59 → 94287082 (last 6 digits: 287082).
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertTrue(Totp::verify($secret, '287082', 59));
        $this->assertTrue(Totp::verify($secret, '287082', 59 + 30), 'one step of clock drift is allowed');
        $this->assertFalse(Totp::verify($secret, '287082', 59 + 90));
    }
}
