<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;

/**
 * One online payment gateway. Every driver follows the same safety rules:
 *  - a notification is only a hint: it must pass the gateway's signature check (where the gateway signs it), and
 *    the payment is then confirmed by asking the gateway's own API (`confirm`), never by trusting the body;
 *  - the confirmed amount must equal the attempt's amount, in rand;
 *  - credentials come from the encrypted PaymentGateway row and are never logged.
 */
interface Gateway
{
    public function driver(): string;

    public function label(): string;

    /** @return list<array{key: string, label: string, secret: bool}> */
    public function credentialFields(): array;

    /**
     * Start a payment: where to send the provider's browser.
     *
     * @return array{type: 'redirect', url: string}|array{type: 'form', action: string, fields: array<string, string>}
     */
    public function start(PaymentAttempt $attempt, Invoice $invoice, PaymentGateway $config, string $returnUrl, string $notifyUrl): array;

    /**
     * Check a notification's signature and say which attempt it is about. Null when the signature is wrong.
     *
     * @return array{event_id: string, reference: string|null, gateway_reference: string|null, payload: array<string, mixed>}|null
     */
    public function parseNotification(Request $request, PaymentGateway $config): ?array;

    /**
     * Ask the gateway whether the attempt was paid.
     *
     * @return array{state: 'paid'|'failed'|'pending', amount_cents: int|null, gateway_reference: string|null}
     */
    public function confirm(PaymentAttempt $attempt, PaymentGateway $config): array;

    /** @return array{ok: bool, message: string} */
    public function test(PaymentGateway $config): array;
}
