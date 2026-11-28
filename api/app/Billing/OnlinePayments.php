<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditLogger;
use App\Billing\Gateways\GatewayRegistry;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Paying invoices online. A notification never marks an invoice paid by itself: it must pass the gateway's
 * signature check, is stored once per event id, and the payment is then confirmed with the gateway's API, for
 * exactly the attempt's amount. The same confirmation runs when the provider returns from the gateway, so a
 * missed notification never leaves a paid invoice unpaid.
 */
final class OnlinePayments
{
    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly Payments $payments,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return array{attempt: PaymentAttempt, checkout: array<string, mixed>} */
    public function start(Invoice $invoice, string $driver, string $returnUrl, User $actor): array
    {
        $config = $this->gateways->config($driver);
        if (! $config->exists || ! $config->enabled || ! $this->gateways->isComplete($config)) {
            throw ValidationException::withMessages(['gateway' => 'This payment method is not available.']);
        }
        if ($invoice->status !== Invoice::ISSUED || $invoice->balanceCents() < 1) {
            throw ValidationException::withMessages(['invoice' => 'This invoice has nothing left to pay.']);
        }

        $attempt = PaymentAttempt::query()->create([
            'invoice_id' => $invoice->id, 'gateway' => $driver, 'reference' => 'SW'.Str::upper(Str::random(18)),
            'amount_cents' => $invoice->balanceCents(), 'created_by' => $actor->id,
        ]);
        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        $checkout = $this->gateways->driver($driver)->start($attempt, $invoice, $config, $returnUrl.$separator.'attempt='.$attempt->id, route('api.v1.payments.notify', ['driver' => $driver]));
        $this->audit->record('billing.online_payment_started', $attempt, null, ['invoice' => $invoice->number, 'gateway' => $driver, 'amount_cents' => $attempt->amount_cents], $actor);

        return ['attempt' => $attempt, 'checkout' => $checkout];
    }

    /** Handle a gateway notification. Returns false when the signature is wrong. */
    public function notify(string $driver, Request $request): bool
    {
        $config = $this->gateways->config($driver);
        $parsed = $config->exists ? $this->gateways->driver($driver)->parseNotification($request, $config) : null;
        if ($parsed === null) {
            $this->context->runAs(null, fn () => $this->audit->record('billing.gateway_notification_rejected', null, null, ['gateway' => $driver, 'ip' => $request->ip()]), platform: true);

            return false;
        }

        $attempt = $this->context->runAs(null, fn (): ?PaymentAttempt => PaymentAttempt::query()
            ->when($parsed['reference'], fn ($q, $ref) => $q->where('reference', $ref), fn ($q) => $q->where('gateway_reference', $parsed['gateway_reference'] ?? '-'))
            ->where('gateway', $driver)->first(), platform: true);

        try {
            // In its own savepoint, so a duplicate (unique gateway + event id) never spoils a surrounding transaction.
            $event = DB::transaction(fn () => PaymentEvent::query()->create(['gateway' => $driver, 'event_id' => $parsed['event_id'], 'payment_attempt_id' => $attempt?->id, 'payload' => $parsed['payload']]));
        } catch (UniqueConstraintViolationException) {
            return true; // already processed
        }

        if ($attempt === null) {
            $event->update(['outcome' => 'No matching payment attempt']);

            return true;
        }
        $tenant = Tenant::query()->findOrFail($attempt->tenant_id);
        $outcome = $this->context->runAs($tenant, fn () => $this->confirm($attempt->refresh()));
        $event->update(['outcome' => $outcome]);

        return true;
    }

    /** Ask the gateway about an attempt and, if it is paid for the right amount, record the payment. */
    public function confirm(PaymentAttempt $attempt): string
    {
        if ($attempt->status !== 'started') {
            return "Already {$attempt->status}";
        }
        $config = $this->gateways->config($attempt->gateway);
        try {
            $result = $this->gateways->driver($attempt->gateway)->confirm($attempt, $config);
        } catch (Throwable $e) {
            report($e);

            return 'Gateway could not be reached';
        }

        if ($result['state'] === 'failed') {
            $attempt->update(['status' => 'failed', 'outcome' => 'The gateway reported the payment as not completed.']);

            return 'Failed';
        }
        if ($result['state'] !== 'paid') {
            return 'Pending';
        }
        if ($result['amount_cents'] !== $attempt->amount_cents) {
            $attempt->update(['status' => 'failed', 'outcome' => "Amount mismatch: gateway {$result['amount_cents']}, expected {$attempt->amount_cents}. HQ to review."]);
            $this->audit->record('billing.online_payment_mismatch', $attempt, null, ['gateway_amount' => $result['amount_cents'], 'expected' => $attempt->amount_cents]);

            return 'Amount mismatch';
        }

        try {
            $this->payments->record($attempt->invoice, 'gateway', $attempt->gateway, $result['gateway_reference'] ?? $attempt->reference, $attempt->amount_cents, $attempt->reference, today(), null);
            $attempt->update(['status' => 'paid', 'gateway_reference' => $result['gateway_reference'] ?? $attempt->gateway_reference, 'outcome' => 'Paid']);

            return 'Paid';
        } catch (ValidationException|UniqueConstraintViolationException $e) {
            // Already paid another way (e.g. EFT recorded meanwhile, or the same transaction twice): HQ refunds by hand.
            $attempt->update(['status' => 'paid', 'outcome' => 'Paid at the gateway, but the invoice was already settled. HQ to review for a refund.']);
            $this->audit->record('billing.online_payment_duplicate', $attempt, null, ['invoice' => $attempt->invoice->number]);

            return 'Duplicate';
        }
    }
}
