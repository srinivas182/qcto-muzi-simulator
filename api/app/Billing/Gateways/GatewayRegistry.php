<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Models\PaymentGateway;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The gateways SimuWork supports, and the ones Super Admin has switched on. */
final class GatewayRegistry
{
    /** @return array<string, class-string<Gateway>> */
    public static function drivers(): array
    {
        return ['paystack' => Paystack::class, 'payfast' => PayFast::class, 'peach' => Peach::class, 'ozow' => Ozow::class, 'yoco' => Yoco::class];
    }

    public function driver(string $key): Gateway
    {
        $class = self::drivers()[$key] ?? throw new NotFoundHttpException('Unknown payment gateway.');

        return app($class);
    }

    public function config(string $key): PaymentGateway
    {
        $this->driver($key);

        return PaymentGateway::query()->firstOrNew(['driver' => $key], ['enabled' => false, 'mode' => 'test', 'credentials' => []]);
    }

    /** @return Collection<int, PaymentGateway> */
    public function enabled(): Collection
    {
        return PaymentGateway::query()->where('enabled', true)->orderBy('display_order')->orderBy('driver')->get()
            ->filter(fn (PaymentGateway $g) => isset(self::drivers()[$g->driver]) && $this->isComplete($g))->values();
    }

    public function isComplete(PaymentGateway $config): bool
    {
        foreach ($this->driver($config->driver)->credentialFields() as $field) {
            if ($config->credential($field['key']) === '') {
                return false;
            }
        }

        return true;
    }
}
