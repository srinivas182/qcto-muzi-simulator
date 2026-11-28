<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\BillingSetting;
use App\Models\Invoice;
use App\Models\LicencePool;
use App\Models\Order;
use App\Models\Tenant;
use App\Notifications\InvoiceIssued;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Issues tax invoices. Seller and buyer details are copied onto the invoice as they are on the day, so a later
 * change of address or VAT number never alters an issued invoice. VAT is calculated per line and rounded once.
 */
final class Invoicer
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InvoicePdf $pdf,
    ) {}

    /**
     * @param  list<array{description: string, quantity: int, unit_cents: int, amount_cents: int}>  $lines
     * @param  array{0: Carbon, 1: Carbon}|null  $period
     */
    public function issue(Tenant $tenant, string $kind, array $lines, ?Order $order, ?LicencePool $pool, ?array $period, ?int $dueDays = null): Invoice
    {
        $settings = BillingSetting::current();
        $rate = $settings->vatRate();
        $subtotal = array_sum(array_column($lines, 'amount_cents'));
        $vat = array_sum(array_map(fn (array $l) => PriceCalculator::vat($l['amount_cents'], $rate), $lines));
        $dueDays ??= ($tenant->payment_terms['mode'] ?? 'pay_first') === 'on_account' ? (int) ($tenant->payment_terms['days'] ?? 0) : $settings->due_days;

        $invoice = DB::transaction(fn (): Invoice => $this->context->runAs($tenant, fn (): Invoice => Invoice::query()->create([
            'number' => DocumentNumbers::next('invoice', $settings->invoice_prefix),
            'kind' => $kind,
            'order_id' => $order?->id,
            'licence_pool_id' => $pool?->id,
            'period_start' => $period[0] ?? null,
            'period_end' => $period[1] ?? null,
            'issue_date' => today(),
            'due_date' => today()->addDays($dueDays),
            'seller' => self::seller($settings),
            'buyer' => self::buyer($tenant),
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'vat_rate' => $rate,
            'vat_cents' => $vat,
            'total_cents' => $subtotal + $vat,
        ])));

        $email = $tenant->billing_email ?? $tenant->contact_email;
        if ($email !== null) {
            DB::afterCommit(fn () => Notification::route('mail', $email)->notify(new InvoiceIssued($invoice->number, $invoice->total_cents, $invoice->due_date->toDateString(), $this->pdf->render($invoice))));
        }

        return $invoice;
    }

    /** @return array<string, mixed> */
    public static function seller(BillingSetting $s): array
    {
        return [
            'name' => $s->seller_name ?? config('app.name'),
            'registration_number' => $s->registration_number,
            'vat_number' => $s->vat_number,
            'address' => $s->address,
            'email' => $s->email,
            'phone' => $s->phone,
            'bank' => $s->bank,
        ];
    }

    /** @return array<string, mixed> */
    public static function buyer(Tenant $t): array
    {
        return [
            'name' => $t->legal_name ?? $t->name,
            'trading_name' => $t->name,
            'registration_number' => $t->registration_number,
            'vat_number' => $t->vat_number,
            'accreditation_number' => $t->accreditation_number,
            'address' => $t->address,
            'email' => $t->billing_email ?? $t->contact_email,
        ];
    }
}
