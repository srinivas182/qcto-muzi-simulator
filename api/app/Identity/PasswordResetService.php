<?php

declare(strict_types=1);

namespace App\Identity;

use App\Models\PasswordReset;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Email password reset for staff. Never reveals whether an address has an account.
 * A successful reset signs the person out everywhere.
 */
final class PasswordResetService
{
    public function request(string $email, string $baseUrl): void
    {
        $user = User::query()
            ->whereRaw('lower(email) = ?', [Str::lower(trim($email))])
            ->whereNotNull('password')
            ->first();

        if ($user === null || ! $user->isActive()) {
            return;
        }

        $token = Str::random(48);
        $reset = new PasswordReset([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes((int) config('identity.password_reset.ttl_minutes')),
        ]);
        $reset->tenant_id = $user->tenant_id;
        $reset->save();

        $user->notify(new ResetPasswordLink(rtrim($baseUrl, '/').'/reset-password?token='.$token));
    }

    public function reset(string $token, string $password): void
    {
        $reset = PasswordReset::query()->where('token_hash', hash('sha256', $token))->first();

        if ($reset === null || $reset->used_at !== null || $reset->expires_at->isPast()) {
            throw ValidationException::withMessages(['token' => 'This reset link has expired. Request a new one.']);
        }

        if (strlen($password) < 10) {
            throw ValidationException::withMessages(['password' => 'Choose a password of at least 10 characters.']);
        }

        $user = User::query()->findOrFail($reset->user_id);
        $user->forceFill(['password' => $password])->save();
        $user->tokens()->delete();
        $reset->forceFill(['used_at' => now()])->save();
    }
}
