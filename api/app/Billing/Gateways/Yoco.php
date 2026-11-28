<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Yoco Checkout API (cards): hosted checkout; Standard Webhooks signatures (HMAC-SHA256, whsec_ secret). */
final class Yoco implements Gateway
{
    private const API = 'https://payments.yoco.com/api';

    /** Yoco recommends rejecting webhooks older than 3 minutes (replay protection). */
    private const TOLERANCE_SECONDS = 180;

    public function driver(): string
    {
        return 'yoco';
    }

    public function label(): string
    {
        return 'Yoco';
    }

    public function credentialFields(): array
    {
        return [['key' => 'secret_key', 'label' => 'Secret key', 'secret' => true], ['key' => 'webhook_secret', 'label' => 'Webhook signing secret (whsec_…)', 'secret' => true]];
    }

    /** @return array{type: 'redirect', url: string} */
    public function start(PaymentAttempt $attempt, Invoice $invoice, PaymentGateway $config, string $returnUrl, string $notifyUrl): array
    {
        $res = Http::withToken($config->credential('secret_key'))->acceptJson()->timeout(15)->post(self::API.'/checkouts', [
            'amount' => $attempt->amount_cents,
            'currency' => 'ZAR',
            'successUrl' => $returnUrl,
            'cancelUrl' => $returnUrl,
            'failureUrl' => $returnUrl,
            'metadata' => ['reference' => $attempt->reference, 'invoice' => $invoice->number],
        ]);
        $url = $res->json('redirectUrl');
        if (! $res->successful() || ! is_string($url)) {
            throw new RuntimeException('Yoco could not start the payment ('.$res->status().').');
        }
        $attempt->update(['gateway_reference' => (string) $res->json('id')]);

        return ['type' => 'redirect', 'url' => $url];
    }

    public function parseNotification(Request $request, PaymentGateway $config): ?array
    {
        $id = (string) $request->header('webhook-id');
        $timestamp = (string) $request->header('webhook-timestamp');
        if ($id === '' || ! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return null;
        }
        $body = $request->getContent();
        $key = base64_decode(preg_replace('/^whsec_/', '', $config->credential('webhook_secret')) ?? '', true) ?: '';
        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
        $ok = false;
        foreach (explode(' ', (string) $request->header('webhook-signature')) as $part) {
            [$version, $sig] = array_pad(explode(',', $part, 2), 2, '');
            $ok = $ok || ($version === 'v1' && hash_equals($expected, $sig));
        }
        if (! $ok) {
            return null;
        }
        $event = (array) json_decode($body, true);

        return [
            'event_id' => $id,
            'reference' => $event['payload']['metadata']['reference'] ?? null,
            'gateway_reference' => $event['payload']['metadata']['checkoutId'] ?? null,
            'payload' => ['type' => $event['type'] ?? null, 'checkout' => $event['payload']['metadata']['checkoutId'] ?? null, 'amount' => $event['payload']['amount'] ?? null],
        ];
    }

    public function confirm(PaymentAttempt $attempt, PaymentGateway $config): array
    {
        if ($attempt->gateway_reference === null) {
            return ['state' => 'pending', 'amount_cents' => null, 'gateway_reference' => null];
        }
        $res = Http::withToken($config->credential('secret_key'))->acceptJson()->timeout(15)->get(self::API.'/checkouts/'.rawurlencode($attempt->gateway_reference));
        $status = (string) $res->json('status');
        $paid = $res->successful() && $status === 'completed' && $res->json('currency') === 'ZAR';

        return [
            'state' => $paid ? 'paid' : (in_array($status, ['failed', 'expired', 'cancelled'], true) ? 'failed' : 'pending'),
            'amount_cents' => $paid ? (int) $res->json('amount') : null,
            'gateway_reference' => $attempt->gateway_reference,
        ];
    }

    public function test(PaymentGateway $config): array
    {
        $res = Http::withToken($config->credential('secret_key'))->acceptJson()->timeout(10)->get(self::API.'/webhooks');

        return $res->successful() ? ['ok' => true, 'message' => 'Yoco accepted the secret key.'] : ['ok' => false, 'message' => 'Yoco refused the secret key ('.$res->status().').'];
    }
}
