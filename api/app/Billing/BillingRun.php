<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditLogger;
use App\Cohorts\Enrolments;
use App\Models\Invoice;
use App\Models\LicencePool;
use App\Models\Tenant;
use App\Notifications\BillingReminder;
use App\Qcto\QctoReminders;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Notification;

/**
 * The daily billing run (`billing:daily`), safe to run more than once a day:
 *  1. expire finished pools (fixed term past its end; cancelled monthly pools after their last month);
 *  2. invoice monthly pools for the current month (once: one invoice per pool per month);
 *  3. payment reminders at the due date, then after 7, 14 and 30 days (30 days = buying and enrolling blocked);
 *  4. renewal reminders 30 and 7 days before a fixed-term pool ends;
 *  5. seats of learners who never started go back to their pool (14-day no-show rule);
 *  6. QCTO enrolment upload reminders (5 working days before and when late).
 */
final class BillingRun
{
    public const REMINDER_DAYS = [1 => 0, 2 => 7, 3 => 14, 4 => 30];

    public function __construct(
        private readonly TenantContext $context,
        private readonly Invoicer $invoicer,
        private readonly AuditLogger $audit,
        private readonly Enrolments $enrolments,
        private readonly QctoReminders $qcto,
    ) {}

    /** @return array{expired: int, invoiced: int, reminders: int, renewals: int, no_shows: int, qcto_reminders: int} */
    public function run(): array
    {
        $totals = ['expired' => 0, 'invoiced' => 0, 'reminders' => 0, 'renewals' => 0, 'no_shows' => 0, 'qcto_reminders' => 0];
        foreach (Tenant::query()->where('status', '!=', Tenant::STATUS_ARCHIVED)->get() as $tenant) {
            $result = $this->context->runAs($tenant, fn (): array => $this->forTenant($tenant));
            foreach ($result as $k => $v) {
                $totals[$k] += $v;
            }
        }

        return $totals;
    }

    /** @return array{expired: int, invoiced: int, reminders: int, renewals: int, no_shows: int} */
    private function forTenant(Tenant $tenant): array
    {
        $email = $tenant->billing_email ?? $tenant->contact_email;
        $expired = 0;
        $invoiced = 0;
        $reminders = 0;
        $renewals = 0;

        foreach (LicencePool::query()->where('status', LicencePool::ACTIVE)->whereNotNull('ends_on')->where('ends_on', '<', today())->get() as $pool) {
            $pool->update(['status' => LicencePool::EXPIRED]);
            $this->audit->record('billing.licence_expired', $pool, null, ['ends_on' => $pool->ends_on?->toDateString()]);
            $expired++;
        }

        $month = today()->startOfMonth();
        $pools = LicencePool::query()->with('package')->where('status', LicencePool::ACTIVE)->where('billing', 'monthly')
            ->where('cancel_at_period_end', false)->where('starts_on', '<', $month)->get();
        foreach ($pools as $pool) {
            if (Invoice::query()->where('licence_pool_id', $pool->id)->where('period_start', $month)->exists()) {
                continue;
            }
            $seats = (int) ($pool->seats_next_month ?? $pool->seats);
            $end = $month->copy()->endOfMonth()->startOfDay();
            $line = ['description' => "{$pool->package->name}: {$seats} seats, {$month->format('F Y')}", 'quantity' => $seats, 'unit_cents' => $pool->unit_price_cents, 'amount_cents' => $seats * $pool->unit_price_cents];
            $this->invoicer->issue($tenant, 'monthly', [$line], null, $pool, [$month, $end]);
            $pool->update(['seats' => max($seats, SeatUsage::used($pool)), 'seats_next_month' => $seats]);
            $invoiced++;
        }

        foreach (Invoice::query()->where('status', Invoice::ISSUED)->where('due_date', '<=', today())->get() as $invoice) {
            $level = 0;
            foreach (self::REMINDER_DAYS as $l => $days) {
                if ($invoice->daysOverdue() >= $days) {
                    $level = $l;
                }
            }
            if ($level > $invoice->reminder_level) {
                $invoice->update(['reminder_level' => $level]);
                if ($email !== null) {
                    $body = $level === 4
                        ? "Invoice {$invoice->number} is 30 days overdue. Until it is paid, your academy cannot buy seats or enrol new learners. Learners already enrolled keep learning."
                        : "Invoice {$invoice->number} for ".InvoicePdf::money($invoice->balanceCents())." was due on {$invoice->due_date->format('j F Y')}. Please pay by EFT with the invoice number as reference, or online.";
                    Notification::route('mail', $email)->notify(new BillingReminder("Payment reminder: {$invoice->number}", $body));
                }
                $reminders++;
            }
        }

        foreach (LicencePool::query()->with('package')->where('status', LicencePool::ACTIVE)->where('billing', 'term')->whereNotNull('ends_on')->get() as $pool) {
            $left = (int) today()->diffInDays($pool->ends_on, false);
            $sent = $pool->renewal_reminders;
            foreach ([30, 7] as $mark) {
                if ($left <= $mark && $left >= 0 && ! in_array($mark, $sent, true)) {
                    $sent[] = $mark;
                    if ($email !== null) {
                        Notification::route('mail', $email)->notify(new BillingReminder(
                            "{$pool->package->name} ends in {$left} days",
                            "Your {$pool->seats} seats on {$pool->package->name} end on {$pool->ends_on?->format('j F Y')}. Renew from Licences & packages so learners keep their access.",
                        ));
                    }
                    $renewals++;
                    break;
                }
            }
            if ($sent !== $pool->renewal_reminders) {
                $pool->update(['renewal_reminders' => $sent]);
            }
        }

        return ['expired' => $expired, 'invoiced' => $invoiced, 'reminders' => $reminders, 'renewals' => $renewals, 'no_shows' => $this->enrolments->returnNoShows(), 'qcto_reminders' => $this->qcto->run($tenant)];
    }
}
