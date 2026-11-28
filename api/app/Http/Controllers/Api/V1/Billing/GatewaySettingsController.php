<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Audit\AuditLogger;
use App\Billing\Gateways\GatewayRegistry;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Super Admin: switch payment gateways on or off, choose test or live, and set their keys. Keys can be written
 * but never read back: the API only says whether each is set and shows its last 4 characters.
 */
final class GatewaySettingsController extends Controller
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (string $d) => $this->present($d), array_keys(GatewayRegistry::drivers())), 'notify_base' => url('/api/v1/payments')]);
    }

    public function update(Request $request, string $driver): JsonResponse
    {
        $gateway = $this->gateways->driver($driver);
        $keys = array_column($gateway->credentialFields(), 'key');
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', Rule::in(['test', 'live'])],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'credentials' => ['sometimes', 'array:'.implode(',', $keys)],
            'credentials.*' => ['nullable', 'string', 'max:500'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $config = $this->gateways->config($driver);
        $before = ['enabled' => $config->enabled, 'mode' => $config->mode];

        $credentials = $config->credentials ?? [];
        foreach ((array) ($data['credentials'] ?? []) as $key => $value) {
            if ($value === null || $value === '') {
                unset($credentials[$key]);
            } else {
                $credentials[$key] = trim((string) $value);
            }
        }
        $config->fill(array_intersect_key($data, array_flip(['enabled', 'mode', 'display_order'])) + ['credentials' => $credentials, 'updated_by' => $actor->id]);
        if (isset($data['credentials']) || isset($data['mode'])) {
            $config->fill(['last_tested_at' => null, 'last_test_ok' => null, 'last_test_message' => null]);
        }
        if ($config->enabled && ! $this->gateways->isComplete($config)) {
            throw ValidationException::withMessages(['enabled' => 'Enter every key before switching this gateway on.']);
        }
        $config->save();

        // Never audit the key values: only which keys changed.
        $this->context->runAs(null, fn () => $this->audit->record('billing.gateway_changed', $config, ['before' => $before, 'after' => ['enabled' => $config->enabled, 'mode' => $config->mode]], [
            'gateway' => $driver, 'keys_changed' => array_keys((array) ($data['credentials'] ?? [])),
        ], $actor), platform: true);

        return response()->json(['data' => $this->present($driver)]);
    }

    public function test(Request $request, string $driver): JsonResponse
    {
        $config = $this->gateways->config($driver);
        if (! $this->gateways->isComplete($config)) {
            throw ValidationException::withMessages(['credentials' => 'Enter every key first.']);
        }
        try {
            $result = $this->gateways->driver($driver)->test($config);
        } catch (\Throwable) {
            $result = ['ok' => false, 'message' => 'The gateway could not be reached.'];
        }
        $config->fill(['last_tested_at' => now(), 'last_test_ok' => $result['ok'], 'last_test_message' => $result['message']])->save();

        return response()->json(['data' => $this->present($driver)]);
    }

    /** @return array<string, mixed> */
    private function present(string $driver): array
    {
        $gateway = $this->gateways->driver($driver);
        $config = $this->gateways->config($driver);

        return [
            'driver' => $driver,
            'label' => $gateway->label(),
            'enabled' => $config->enabled,
            'mode' => $config->mode,
            'display_order' => $config->display_order,
            'complete' => $this->gateways->isComplete($config),
            'fields' => array_map(function (array $f) use ($config): array {
                $value = $config->credential($f['key']);

                return $f + ['set' => $value !== '', 'hint' => $value === '' ? null : ($f['secret'] ? '••••'.substr($value, -4) : $value)];
            }, $gateway->credentialFields()),
            'last_test' => $config->last_tested_at ? ['at' => $config->last_tested_at->toIso8601String(), 'ok' => $config->last_test_ok, 'message' => $config->last_test_message] : null,
            'notify_url' => route('api.v1.payments.notify', ['driver' => $driver]),
        ];
    }
}
