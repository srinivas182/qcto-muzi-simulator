<?php

declare(strict_types=1);

namespace App\Identity;

use App\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Authenticator-app two-factor authentication: set up, confirm, disable. */
final class TwoFactorService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array{secret: string, otpauth_uri: string} */
    public function begin(User $user): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['two_factor' => 'Two-factor authentication is already on.']);
        }

        $secret = Totp::generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();

        return [
            'secret' => $secret,
            'otpauth_uri' => Totp::provisioningUri($secret, $user->email ?? $user->phone ?? $user->id, (string) config('identity.two_factor.issuer')),
        ];
    }

    /** @return list<string> recovery codes, shown once */
    public function confirm(User $user, string $code): array
    {
        if ($user->two_factor_secret === null || ! Totp::verify($user->two_factor_secret, $code)) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }

        $codes = [];
        for ($i = 0; $i < (int) config('identity.two_factor.recovery_codes'); $i++) {
            $codes[] = Str::lower(Str::random(5).'-'.Str::random(5));
        }

        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        $this->audit->record('auth.two_factor_enabled', $user, null, [], $user);

        return $codes;
    }

    public function disable(User $user, string $code): void
    {
        if (! $user->hasTwoFactorEnabled() || $user->two_factor_secret === null || ! Totp::verify($user->two_factor_secret, $code)) {
            throw ValidationException::withMessages(['code' => 'Enter a current code from your authenticator app.']);
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        $this->audit->record('auth.two_factor_disabled', $user, null, [], $user);
    }
}
