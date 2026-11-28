<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Paystack (card, Instant EFT, Capitec Pay): hosted checkout; webhooks signed with HMAC-SHA512 of the body. */
final class Paystack implements Gateway
{
    private const API = 'https://api.paystack.co';

    public function driver(): string
    {
        return 'paystack';
    }

    public function label(): string
    {
        return 'Paystack';
    }

    public function credentialFields(): array
    {
        return [['key' => 'public_key', 'label' => 'Public key', 'secret' => false], ['key' => 'secret_key', 'label' => 'Secret key', 'secret' => true]];
    }

    /** @return array{type: 'redirect', url: string} */
    public function start(PaymentAttempt $attempt, Invoice $invoice, PaymentGateway $config, string $returnUrl, string $notifyUrl): array
    {
        $res = Http::withToken($config->credential('secret_key'))->acceptJson()->timeout(15)->post(self::API.'/transaction/initialize', [
            'email' => $invoice->buyer['email'] ?? 'billing@'.parse_url($returnUrl, PHP_URL_HOST),
            'amount' => $attempt->amount_cents,
            'currency' => 'ZAR',
            'reference' => $attempt->reference,
            'callback_url' => $returnUrl,
            'metadata' => ['invoice' => $invoice->number],
        ]);
        $url = $res->json('data.authorization_url');
        if (! $res->successful() || ! is_string($url)) {
            throw new RuntimeException('Paystack could not start the payment: '.($res->json('message') ?? $res->status()));
        }

        return ['type' => 'redirect', 'url' => $url];
    }

    public function parseNotification(Request $request, PaymentGateway $config): ?array
    {
        $body = $request->getContent();
        $expected = hash_hmac('sha512', $body, $config->credential('secret_key'));
        if (! hash_equals($expected, (string) $request->header('x-paystack-signature'))) {
            return null;
        }
        $event = (array) json_decode($body, true);

        return [
            'event_id' => ($event['event'] ?? 'event').':'.($event['data']['id'] ?? $event['data']['reference'] ?? sha1($body)),
            'reference' => $event['data']['reference'] ?? null,
            'gateway_reference' => isset($event['data']['id']) ? (string) $event['data']['id'] : null,
            'payload' => ['event' => $event['event'] ?? null, 'reference' => $event['data']['reference'] ?? null, 'status' => $event['data']['status'] ?? null],
        ];
    }

    public function confirm(PaymentAttempt $attempt, PaymentGateway $config): array
    {
        $res = Http::withToken($config->credential('secret_key'))->acceptJson()->timeout(15)->get(self::API.'/transaction/verify/'.rawurlencode($attempt->reference));
        $status = $res->json('data.status');
        $paid = $res->successful() && $status === 'success' && $res->json('data.currency') === 'ZAR';

        return [
            'state' => $paid ? 'paid' : (in_array($status, ['failed', 'abandoned', 'reversed'], true) ? 'failed' : 'pending'),
            'amount_cents' => $paid ? (int) $res->json('data.amount') : null,
            'gateway_reference' => $res->json('data.id') !== null ? (string) $res->json('data.id') : null,
        ];
    }

    public function test(PaymentGateway $config): array
    {
        $res = Http::withToken($config->credential('secret_key'))->acceptJson()->timeout(10)->get(self::API.'/transaction', ['perPage' => 1]);

        return $res->successful() ? ['ok' => true, 'message' => 'Paystack accepted the secret key.'] : ['ok' => false, 'message' => 'Paystack refused the secret key ('.$res->status().').'];
    }
}
