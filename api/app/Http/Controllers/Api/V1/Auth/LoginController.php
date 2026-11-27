<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Identity\Authenticator;
use App\Identity\OtpService;
use App\Identity\PhoneNumber;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in: mobile number + one-time code (learners), email + password (staff),
 * and the two-factor challenge that follows when 2FA is on.
 */
final class LoginController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly Authenticator $auth,
        private readonly TenantContext $context,
    ) {}

    /** Always answers the same way, so nobody can test which numbers have accounts. */
    public function requestCode(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $channel = null;

        if ($this->users()->where('phone', $phone)->exists()) {
            $channel = $this->otp->send($phone, 'login');
        }

        return response()->json([
            'message' => 'If this number has an account, a code is on its way.',
            'sent_to' => PhoneNumber::mask($phone),
            'channel_hint' => $channel === 'sms' ? 'sms' : 'whatsapp',
        ], 202);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $phone = $this->phone($request);
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);

        $user = $this->users()->where('phone', $phone)->first();

        if ($user === null || ! $this->otp->verify($phone, 'login', $data['code'])) {
            throw ValidationException::withMessages(['code' => 'That code is not valid or has expired.']);
        }

        if ($user->phone_verified_at === null) {
            $user->forceFill(['phone_verified_at' => now()])->save();
        }

        return response()->json($this->auth->complete($user, ...$this->device($request)));
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:200'],
        ]);

        $limiterKey = 'login:'.($this->context->tenantId() ?? 'platform').':'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($limiterKey).' seconds.',
            ])->status(429);
        }

        $user = $this->users()->whereRaw('lower(email) = ?', [Str::lower($data['email'])])->first();

        if ($user === null || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($limiterKey, 60);
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        RateLimiter::clear($limiterKey);

        return response()->json($this->auth->complete($user, ...$this->device($request)));
    }

    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        return response()->json($this->auth->completeChallenge($data['challenge_token'], $data['code'] ?? null, $data['recovery_code'] ?? null));
    }

    /** @return Builder<User> */
    private function users(): Builder
    {
        // In HQ (platform) context only HQ staff may sign in; in a tenant, the tenant scope applies.
        return User::query()->when($this->context->isPlatform(), fn (Builder $q) => $q->whereNull('tenant_id'));
    }

    private function phone(Request $request): string
    {
        $request->validate(['phone' => ['required', 'string', 'max:25']]);
        $phone = PhoneNumber::normalise((string) $request->input('phone'));

        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid mobile number, e.g. 082 123 4567.']);
        }

        return $phone;
    }

    /** @return array{0: string, 1: string} device name and client type */
    private function device(Request $request): array
    {
        $client = $request->input('client') === 'mobile' ? 'mobile' : 'web';
        $device = (string) ($request->input('device_name') ?: Str::limit((string) $request->userAgent(), 90, '') ?: 'Unknown device');

        return [$device, $client];
    }
}
