<?php

declare(strict_types=1);

namespace App\Identity;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finishes a sign-in: either issues an API token, or — when two-factor authentication is on —
 * returns a short-lived challenge that must be completed with an authenticator or recovery code.
 */
final class Authenticator
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function complete(User $user, string $device, string $client): array
    {
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['account' => 'This account is disabled. Contact your training provider.']);
        }

        if ($user->hasTwoFactorEnabled()) {
            $challenge = Str::random(48);
            Cache::put($this->challengeKey($challenge), [
                'user_id' => $user->id, 'device' => $device, 'client' => $client,
            ], now()->addMinutes((int) config('identity.two_factor.challenge_ttl_minutes')));

            return ['two_factor_required' => true, 'challenge_token' => $challenge];
        }

        return $this->issueToken($user, $device, $client);
    }

    /** @return array<string, mixed> */
    public function completeChallenge(string $challenge, ?string $code, ?string $recoveryCode): array
    {
        $key = $this->challengeKey($challenge);
        /** @var array{user_id: string, device: string, client: string}|null $pending */
        $pending = Cache::get($key);

        $fail = fn () => ValidationException::withMessages(['code' => 'That code is not valid. Try again.']);

        if ($pending === null || RateLimiter::tooManyAttempts('2fa:'.$key, 5)) {
            Cache::forget($key);
            throw ValidationException::withMessages(['challenge_token' => 'Sign-in expired. Please start again.']);
        }
        RateLimiter::hit('2fa:'.$key, 300);

        $user = User::query()->find($pending['user_id']);
        if ($user === null || $user->two_factor_secret === null) {
            throw $fail();
        }

        $ok = false;
        if ($code !== null && $code !== '') {
            $ok = Totp::verify($user->two_factor_secret, $code);
        } elseif ($recoveryCode !== null && $recoveryCode !== '') {
            $codes = $user->two_factor_recovery_codes ?? [];
            $index = array_search(trim($recoveryCode), $codes, true);
            if ($index !== false) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                $ok = true;
            }
        }

        if (! $ok) {
            throw $fail();
        }

        Cache::forget($key);

        return $this->issueToken($user, $pending['device'], $pending['client']);
    }

    /** @return array<string, mixed> */
    public function issueToken(User $user, string $device, string $client): array
    {
        $days = $client === 'mobile' ? (int) config('identity.tokens.mobile_days') : (int) config('identity.tokens.web_days');
        $token = $user->createToken(mb_substr($device, 0, 100), ['*'], now()->addDays($days));
        $this->audit->record('auth.login', $user, null, ['device' => mb_substr($device, 0, 100), 'client' => $client], $user);

        $user->forceFill(['last_login_at' => now()])->save();

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => $this->present($user),
        ];
    }

    /** @return array<string, mixed> */
    public function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'tenant_id' => $user->tenant_id,
            'is_platform_staff' => $user->isPlatformStaff(),
            'two_factor_enabled' => $user->hasTwoFactorEnabled(),
            'consent_required' => $this->consents->outstanding($user),
        ];
    }

    private function challengeKey(string $challenge): string
    {
        return '2fa-challenge:'.($this->context->tenantId() ?? 'platform').':'.hash('sha256', $challenge);
    }
}
