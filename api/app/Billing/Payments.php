<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditLogger;
use App\Models\BillingSetting;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\LicencePool;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money in (EFT recorded by HQ, or a confirmed gateway payment) and credit notes. When an invoice is paid in
 * full, its order becomes seats. Everything runs in the provider's context and is audited there.
 */
final class Payments
{
    public function __construct(
        private readonly LicenceOrders $orders,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function recordEft(Invoice $invoice, int $amountCents, string $reference, Carbon $paidOn, User $actor): Payment
    {
        return $this->record($invoice, Payment::EFT, null, null, $amountCents, $reference, $paidOn, $actor);
    }

    public function record(Invoice $invoice, string $method, ?string $gateway, ?string $gatewayReference, int $amountCents, ?string $reference, Carbon $paidOn, ?User $actor): Payment
    {
        return $this->context->runAs($invoice->tenant, fn (): Payment => DB::transaction(function () use ($invoice, $method, $gateway, $gatewayReference, $amountCents, $reference, $paidOn, $actor): Payment {
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== Invoice::ISSUED) {
                throw ValidationException::withMessages(['invoice' => "Invoice {$locked->number} is already {$locked->status}."]);
            }
            if ($amountCents < 1 || $amountCents > $locked->balanceCents()) {
                throw ValidationException::withMessages(['amount_cents' => 'The amount must be more than zero and no more than what is still owed.']);
            }

            $payment = Payment::query()->create([
                'invoice_id' => $locked->id, 'method' => $method, 'gateway' => $gateway, 'gateway_reference' => $gatewayReference,
                'amount_cents' => $amountCents, 'reference' => $reference, 'paid_on' => $paidOn, 'recorded_by' => $actor?->id,
            ]);
            $locked->paid_cents += $amountCents;
            $this->audit->record('billing.payment_recorded', $payment, null, ['invoice' => $locked->number, 'amount_cents' => $amountCents, 'method' => $gateway ?? $method], $actor);

            if ($locked->paid_cents >= $locked->total_cents) {
                $locked->status = Invoice::PAID;
                $locked->paid_at = now();
                $this->audit->record('billing.invoice_paid', $locked, null, ['invoice' => $locked->number], $actor);
            }
            $locked->save();

            if ($locked->status === Invoice::PAID && $locked->order_id !== null) {
                $this->orders->fulfil(Order::query()->findOrFail($locked->order_id));
            }

            return $payment;
        }));
    }

    /** Cancel an unpaid invoice in full with a credit note; its order and any seats it gave are taken back. */
    public function credit(Invoice $invoice, string $reason, User $actor): CreditNote
    {
        return $this->context->runAs($invoice->tenant, fn (): CreditNote => DB::transaction(function () use ($invoice, $reason, $actor): CreditNote {
            /** @var Invoice $locked */
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($locked->status !== Invoice::ISSUED || $locked->paid_cents > 0) {
                throw ValidationException::withMessages(['invoice' => 'Only an unpaid invoice can be credited. Refunds of paid invoices are handled outside SimuWork.']);
            }

            $note = CreditNote::query()->create([
                'invoice_id' => $locked->id,
                'number' => DocumentNumbers::next('credit_note', BillingSetting::current()->credit_note_prefix),
                'issue_date' => today(),
                'subtotal_cents' => $locked->subtotal_cents, 'vat_cents' => $locked->vat_cents, 'total_cents' => $locked->total_cents,
                'reason' => $reason, 'created_by' => $actor->id,
            ]);
            $locked->update(['status' => Invoice::CREDITED]);

            if ($locked->order_id !== null) {
                $order = Order::query()->findOrFail($locked->order_id);
                $pool = LicencePool::query()->lockForUpdate()->findOrFail($order->licence_pool_id);
                if ($order->kind === Order::NEW) {
                    $pool->update(['status' => LicencePool::CANCELLED]);
                } elseif ($order->status === 'paid') { // an on-account top-up already gave seats
                    $pool->seats = max(SeatUsage::used($pool), $pool->seats - $order->seats);
                    if ($pool->isMonthly()) {
                        $pool->seats_next_month = max($pool->seats, (int) $pool->seats_next_month - $order->seats);
                    }
                    $pool->save();
                }
                $order->update(['status' => 'cancelled']);
            }
            $this->audit->record('billing.invoice_credited', $locked, null, ['invoice' => $locked->number, 'credit_note' => $note->number, 'reason' => $reason], $actor);

            return $note;
        }));
    }
}
