<?php

declare(strict_types=1);

namespace App\Identity;

use App\Identity\Messaging\CodeDelivery;
use App\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-time codes sent by WhatsApp/SMS. Codes are stored hashed, expire after 10 minutes,
 * allow 5 attempts and at most 3 sends per 10 minutes per number. Scoped per tenant.
 */
final class OtpService
{
    public function __construct(
        private readonly CodeDelivery $delivery,
        private readonly TenantContext $context,
    ) {}

    /** @return string channel used */
    public function send(string $phone, string $purpose): string
    {
        $key = $this->key($phone, $purpose);
        $limiterKey = 'otp-send:'.$key;
        $maxSends = (int) config('identity.otp.max_sends_per_window');

        if (RateLimiter::tooManyAttempts($limiterKey, $maxSends)) {
            throw new ThrottleRequestsException('Too many codes requested. Try again shortly.', null, [
                'Retry-After' => RateLimiter::availableIn($limiterKey),
            ]);
        }
        RateLimiter::hit($limiterKey, (int) config('identity.otp.send_window_minutes') * 60);

        $length = (int) config('identity.otp.length');
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        Cache::put('otp:'.$key, ['hash' => hash('sha256', $code), 'attempts' => 0], now()->addMinutes((int) config('identity.otp.ttl_minutes')));

        return $this->delivery->send($phone, $code);
    }

    public function verify(string $phone, string $purpose, string $code): bool
    {
        $cacheKey = 'otp:'.$this->key($phone, $purpose);
        /** @var array{hash: string, attempts: int}|null $entry */
        $entry = Cache::get($cacheKey);

        if ($entry === null) {
            return false;
        }

        $entry['attempts']++;
        if ($entry['attempts'] > (int) config('identity.otp.max_attempts')) {
            Cache::forget($cacheKey);

            return false;
        }

        if (hash_equals($entry['hash'], hash('sha256', trim($code)))) {
            Cache::forget($cacheKey);

            return true;
        }

        Cache::put($cacheKey, $entry, now()->addMinutes((int) config('identity.otp.ttl_minutes')));

        return false;
    }

    private function key(string $phone, string $purpose): string
    {
        $scope = $this->context->tenantId() ?? 'platform';

        return hash('sha256', $scope.'|'.$purpose.'|'.$phone);
    }
}
