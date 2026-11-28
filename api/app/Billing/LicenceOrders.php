<?php

declare(strict_types=1);

namespace App\Billing;

use App\Audit\AuditLogger;
use App\Models\Invoice;
use App\Models\LicencePool;
use App\Models\Order;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Buying seats: new pools, top-ups, monthly seat changes and cancellation, and turning paid orders into seats.
 *
 * Rules (Sprint 3 decisions):
 *  - the price per seat is locked when a pool is bought; top-ups use it too;
 *  - fixed-term top-ups are charged only for the days left in the term; monthly pools charge the rest of the
 *    current month, then a full month on the 1st;
 *  - "pay first" providers get their seats when the invoice is paid; "on account" providers get them at once;
 *  - a provider with an invoice more than 30 days overdue cannot buy more (learners already enrolled keep going).
 */
final class LicenceOrders
{
    public const BLOCK_AFTER_DAYS = 30;

    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly Invoicer $invoicer,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{order: Order, invoice: Invoice} */
    public function buy(Tenant $tenant, Package $package, int $seats, User $actor): array
    {
        $this->assertCanBuy($tenant);
        if ($package->status !== Package::PUBLISHED) {
            throw ValidationException::withMessages(['package' => 'This package is not on sale.']);
        }
        $quote = $this->prices->quote($package, $seats, 0.0);

        return DB::transaction(function () use ($tenant, $package, $seats, $quote, $actor): array {
            $pool = LicencePool::query()->create([
                'package_id' => $package->id, 'programme_id' => $package->programme_id, 'billing' => $package->billing,
                'term_months' => $package->term_months, 'unit_price_cents' => $quote['unit_price_cents'],
                'seats' => 0, 'seats_next_month' => $package->billing === Package::MONTHLY ? $seats : null, 'status' => LicencePool::PENDING,
            ]);

            if ($package->billing === Package::TERM) {
                $line = $this->line("{$package->name}: {$seats} seats for {$package->term_months} months", $seats, $quote['unit_price_cents']);
                $period = null;
            } else {
                [$unit, $from, $to, $days, $of] = self::restOfMonth($quote['unit_price_cents']);
                $line = $this->line("{$package->name}: {$seats} seats, {$from->format('j M')}–{$to->format('j M Y')} ({$days} of {$of} days)", $seats, $unit);
                $period = [$from, $to];
            }

            $order = Order::query()->create([
                'package_id' => $package->id, 'licence_pool_id' => $pool->id, 'kind' => Order::NEW, 'seats' => $seats,
                'unit_price_cents' => $quote['unit_price_cents'], 'pricing' => ['band_from' => $quote['band_from'], 'line' => $line], 'ordered_by' => $actor->id,
            ]);
            $invoice = $this->invoicer->issue($tenant, 'order', [$line], $order, $pool, $period);
            $this->audit->record('billing.order_placed', $order, null, ['package' => $package->code, 'seats' => $seats, 'invoice' => $invoice->number], $actor);

            if ($this->onAccount($tenant)) {
                $this->fulfil($order);
            }

            return ['order' => $order->refresh(), 'invoice' => $invoice];
        });
    }

    /** @return array{order: Order, invoice: Invoice} */
    public function topUp(Tenant $tenant, LicencePool $pool, int $extra, User $actor): array
    {
        $this->assertCanBuy($tenant);
        if ($pool->status !== LicencePool::ACTIVE || $pool->cancel_at_period_end) {
            throw ValidationException::withMessages(['pool' => 'Only an active licence can get more seats.']);
        }

        return DB::transaction(function () use ($tenant, $pool, $extra, $actor): array {
            $name = $pool->package->name;
            if ($pool->isMonthly()) {
                [$unit, $from, $to, $days, $of] = self::restOfMonth($pool->unit_price_cents);
                $line = $this->line("{$name}: {$extra} extra seats, {$from->format('j M')}–{$to->format('j M Y')} ({$days} of {$of} days)", $extra, $unit);
            } else {
                /** @var Carbon $start */
                $start = $pool->starts_on;
                /** @var Carbon $end */
                $end = $pool->ends_on;
                $total = (int) $start->diffInDays($end) + 1;
                $left = (int) today()->diffInDays($end) + 1;
                $unit = (int) round($pool->unit_price_cents * $left / $total);
                $line = $this->line("{$name}: {$extra} extra seats until {$end->format('j M Y')} ({$left} of {$total} days)", $extra, $unit);
            }

            $order = Order::query()->create([
                'package_id' => $pool->package_id, 'licence_pool_id' => $pool->id, 'kind' => Order::TOP_UP, 'seats' => $extra,
                'unit_price_cents' => $pool->unit_price_cents, 'pricing' => ['line' => $line], 'ordered_by' => $actor->id,
            ]);
            $invoice = $this->invoicer->issue($tenant, 'order', [$line], $order, $pool, null);
            $this->audit->record('billing.order_placed', $order, null, ['top_up' => true, 'seats' => $extra, 'invoice' => $invoice->number], $actor);

            if ($this->onAccount($tenant)) {
                $this->fulfil($order);
            }

            return ['order' => $order->refresh(), 'invoice' => $invoice];
        });
    }

    /** Monthly pools: how many seats to bill from the 1st of next month. */
    public function setNextMonthSeats(LicencePool $pool, int $seats, User $actor): LicencePool
    {
        if (! $pool->isMonthly() || $pool->status !== LicencePool::ACTIVE) {
            throw ValidationException::withMessages(['seats_next_month' => 'Only an active monthly licence can change its seats.']);
        }
        $min = max($pool->package->min_seats, SeatUsage::used($pool));
        if ($seats < $min) {
            throw ValidationException::withMessages(['seats_next_month' => "Keep at least {$min} seats (the package minimum or the seats in use)."]);
        }
        $before = $pool->seats_next_month;
        $pool->update(['seats_next_month' => $seats]);
        $this->audit->record('billing.seats_next_month_changed', $pool, ['before' => $before, 'after' => $seats], [], $actor);

        return $pool;
    }

    /** Monthly pools: stop at the end of the current month (or undo that). */
    public function setCancelled(LicencePool $pool, bool $cancel, User $actor): LicencePool
    {
        if (! $pool->isMonthly() || $pool->status !== LicencePool::ACTIVE) {
            throw ValidationException::withMessages(['pool' => 'Only an active monthly licence can be cancelled.']);
        }
        $pool->update(['cancel_at_period_end' => $cancel, 'ends_on' => $cancel ? today()->endOfMonth()->toDateString() : null]);
        $this->audit->record($cancel ? 'billing.licence_cancelled' : 'billing.licence_resumed', $pool, null, ['ends_on' => $pool->ends_on?->toDateString()], $actor);

        return $pool;
    }

    /** Turn a paid (or on-account) order into seats. Safe to call twice. */
    public function fulfil(Order $order): void
    {
        if ($order->status === 'paid') {
            return;
        }
        $pool = LicencePool::query()->lockForUpdate()->findOrFail($order->licence_pool_id);

        if ($order->kind === Order::NEW) {
            $starts = today();
            $pool->fill([
                'status' => LicencePool::ACTIVE,
                'seats' => $order->seats,
                'starts_on' => $starts,
                'ends_on' => $pool->isMonthly() ? null : $starts->copy()->addMonthsNoOverflow((int) $pool->term_months)->subDay(),
            ]);
        } else {
            $pool->seats += $order->seats;
            if ($pool->isMonthly()) {
                $pool->seats_next_month = (int) $pool->seats_next_month + $order->seats;
            }
        }
        $pool->save();
        $order->update(['status' => 'paid']);
        $this->audit->record('billing.seats_added', $pool, null, ['order' => $order->id, 'seats' => $order->seats, 'total_seats' => $pool->seats]);
    }

    /** Has this provider an invoice more than 30 days overdue? Then it cannot buy or enrol more. */
    public static function isBlocked(Tenant $tenant): bool
    {
        return app(TenantContext::class)->runAs($tenant, fn (): bool => Invoice::query()
            ->where('status', Invoice::ISSUED)->where('due_date', '<', today()->subDays(self::BLOCK_AFTER_DAYS))->exists());
    }

    private function assertCanBuy(Tenant $tenant): void
    {
        if (self::isBlocked($tenant)) {
            throw new HttpException(423, 'An invoice is more than 30 days overdue. Pay it to buy more seats.');
        }
    }

    private function onAccount(Tenant $tenant): bool
    {
        return ($tenant->payment_terms['mode'] ?? 'pay_first') === 'on_account';
    }

    /** @return array{description: string, quantity: int, unit_cents: int, amount_cents: int} */
    private function line(string $description, int $quantity, int $unit): array
    {
        return ['description' => $description, 'quantity' => $quantity, 'unit_cents' => $unit, 'amount_cents' => $unit * $quantity];
    }

    /**
     * Pro-rated price per seat for the rest of this month.
     *
     * @return array{0: int, 1: Carbon, 2: Carbon, 3: int, 4: int}
     */
    public static function restOfMonth(int $unitCents): array
    {
        $from = today();
        $to = today()->endOfMonth()->startOfDay();
        $days = (int) $from->diffInDays($to) + 1;
        $of = $from->daysInMonth;

        return [(int) round($unitCents * $days / $of), $from, $to, $days, $of];
    }
}
