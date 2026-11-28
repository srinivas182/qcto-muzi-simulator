<?php

declare(strict_types=1);

namespace App\Billing;

use App\Models\Invoice;
use App\Models\LicencePool;
use App\Models\Payment;

/** How invoices and licences are shown to providers and HQ. */
final class BillingPresenter
{
    /** @return array<string, mixed> */
    public static function invoice(Invoice $i, bool $full = false): array
    {
        $data = [
            'id' => $i->id,
            'number' => $i->number,
            'kind' => $i->kind,
            'issue_date' => $i->issue_date->toDateString(),
            'due_date' => $i->due_date->toDateString(),
            'period_start' => $i->period_start?->toDateString(),
            'period_end' => $i->period_end?->toDateString(),
            'subtotal_cents' => $i->subtotal_cents,
            'vat_rate' => (float) $i->vat_rate,
            'vat_cents' => $i->vat_cents,
            'total_cents' => $i->total_cents,
            'paid_cents' => $i->paid_cents,
            'balance_cents' => $i->balanceCents(),
            'status' => $i->status,
            'overdue_days' => $i->daysOverdue(),
            'buyer_name' => $i->buyer['trading_name'] ?? $i->buyer['name'] ?? null,
            'tenant_id' => $i->tenant_id,
            'description' => $i->lines[0]['description'] ?? '',
        ];
        if ($full) {
            $i->loadMissing(['payments', 'creditNote']);
            $data += [
                'lines' => $i->lines,
                'seller' => $i->seller,
                'buyer' => $i->buyer,
                'payments' => $i->payments->map(fn (Payment $p) => [
                    'id' => $p->id, 'method' => $p->method, 'gateway' => $p->gateway, 'amount_cents' => $p->amount_cents,
                    'reference' => $p->reference, 'paid_on' => $p->paid_on->toDateString(),
                ])->all(),
                'credit_note' => $i->creditNote ? ['number' => $i->creditNote->number, 'reason' => $i->creditNote->reason, 'issue_date' => $i->creditNote->issue_date->toDateString()] : null,
            ];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function pool(LicencePool $p): array
    {
        $p->loadMissing(['package', 'programme']);
        $used = SeatUsage::used($p);

        return [
            'id' => $p->id,
            'package' => ['code' => $p->package->code, 'name' => $p->package->name, 'min_seats' => $p->package->min_seats, 'includes_simulators' => $p->package->includes_simulators, 'includes_courses' => $p->package->includes_courses],
            'programme' => ['code' => $p->programme->code, 'title' => $p->programme->title, 'prefix' => $p->programme->prefix],
            'billing' => $p->billing,
            'term_months' => $p->term_months,
            'unit_price_cents' => $p->unit_price_cents,
            'seats' => $p->seats,
            'seats_used' => $used,
            'seats_free' => max(0, $p->seats - $used),
            'seats_next_month' => $p->seats_next_month,
            'starts_on' => $p->starts_on?->toDateString(),
            'ends_on' => $p->ends_on?->toDateString(),
            'days_left' => $p->ends_on ? (int) today()->diffInDays($p->ends_on, false) : null,
            'cancel_at_period_end' => $p->cancel_at_period_end,
            'status' => $p->status,
        ];
    }
}
