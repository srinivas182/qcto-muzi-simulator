<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Peach Payments Hosted Checkout: signed form post (HMAC-SHA256 over the sorted parameters with the secret
 * token). Results are confirmed with Peach's signed status query.
 */
final class Peach implements Gateway
{
    /** Peach result codes that mean "paid". */
    private const SUCCESS = '/^(000\.000\.|000\.100\.1|000\.[36])/';

    public function driver(): string
    {
        return 'peach';
    }

    public function label(): string
    {
        return 'Peach Payments';
    }

    public function credentialFields(): array
    {
        return [['key' => 'entity_id', 'label' => 'Entity ID', 'secret' => false], ['key' => 'secret_token', 'label' => 'Secret token', 'secret' => true]];
    }

    /** @return array{type: 'form', action: string, fields: array<string, string>} */
    public function start(PaymentAttempt $attempt, Invoice $invoice, PaymentGateway $config, string $returnUrl, string $notifyUrl): array
    {
        $fields = [
            'authentication.entityId' => $config->credential('entity_id'),
            'amount' => number_format($attempt->amount_cents / 100, 2, '.', ''),
            'currency' => 'ZAR',
            'merchantTransactionId' => $attempt->reference,
            'nonce' => Str::random(32),
            'paymentType' => 'DB',
            'shopperResultUrl' => $returnUrl,
            'notificationUrl' => $notifyUrl,
        ];
        $fields['signature'] = self::sign($fields, $config->credential('secret_token'));

        return ['type' => 'form', 'action' => $this->host($config).'/checkout', 'fields' => $fields];
    }

    /** Checkout results are signed like requests; the payment itself is always confirmed with `confirm`. */
    public function parseNotification(Request $request, PaymentGateway $config): ?array
    {
        $params = $request->request->all() ?: (array) $request->json()->all();
        $signature = (string) ($params['signature'] ?? '');
        unset($params['signature']);
        if (! hash_equals(self::sign($params, $config->credential('secret_token')), $signature)) {
            return null;
        }
        $reference = $params['merchantTransactionId'] ?? null;

        return [
            'event_id' => 'result:'.($params['id'] ?? $reference).':'.($params['result_code'] ?? $params['result.code'] ?? ''),
            'reference' => is_string($reference) ? $reference : null,
            'gateway_reference' => isset($params['id']) ? (string) $params['id'] : null,
            'payload' => $params,
        ];
    }

    public function confirm(PaymentAttempt $attempt, PaymentGateway $config): array
    {
        $query = ['authentication.entityId' => $config->credential('entity_id'), 'merchantTransactionId' => $attempt->reference];
        $query['signature'] = self::sign($query, $config->credential('secret_token'));
        $res = Http::acceptJson()->timeout(15)->get($this->host($config).'/status', $query);
        $code = (string) ($res->json('result.code') ?? $res->json('result_code') ?? '');
        $paid = $res->successful() && preg_match(self::SUCCESS, $code) === 1 && ($res->json('currency') ?? 'ZAR') === 'ZAR';

        return [
            'state' => $paid ? 'paid' : ($code !== '' && ! str_starts_with($code, '000.200') ? 'failed' : 'pending'),
            'amount_cents' => $paid ? (int) round(((float) $res->json('amount')) * 100) : null,
            'gateway_reference' => $res->json('id') !== null ? (string) $res->json('id') : null,
        ];
    }

    public function test(PaymentGateway $config): array
    {
        $query = ['authentication.entityId' => $config->credential('entity_id'), 'merchantTransactionId' => 'connection-test'];
        $query['signature'] = self::sign($query, $config->credential('secret_token'));
        $res = Http::acceptJson()->timeout(10)->get($this->host($config).'/status', $query);

        return in_array($res->status(), [401, 403], true)
            ? ['ok' => false, 'message' => 'Peach refused the entity ID or secret token ('.$res->status().').']
            : ['ok' => true, 'message' => 'Peach accepted the signed request.'];
    }

    /** @param array<string, mixed> $params */
    public static function sign(array $params, string $secret): string
    {
        ksort($params);
        $message = '';
        foreach ($params as $key => $value) {
            $message .= $key.$value;
        }

        return hash_hmac('sha256', $message, $secret);
    }

    private function host(PaymentGateway $config): string
    {
        return $config->isLive() ? 'https://secure.peachpayments.com' : 'https://testsecure.peachpayments.com';
    }
}
