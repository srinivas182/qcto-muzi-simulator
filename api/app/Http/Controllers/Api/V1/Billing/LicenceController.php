<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Billing\BillingPresenter;
use App\Billing\LicenceOrders;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LicencePool;
use App\Models\Package;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Provider: its licence pools, buying seats, and monthly seat changes. */
final class LicenceController extends Controller
{
    public function __construct(
        private readonly LicenceOrders $orders,
        private readonly TenantContext $context,
    ) {}

    public function index(): JsonResponse
    {
        $pools = LicencePool::query()->with(['package', 'programme'])->whereIn('status', [LicencePool::PENDING, LicencePool::ACTIVE])->orderBy('created_at')->get();
        $past = LicencePool::query()->with(['package', 'programme'])->whereIn('status', [LicencePool::EXPIRED, LicencePool::CANCELLED])->latest()->limit(20)->get();
        $open = Invoice::query()->where('status', Invoice::ISSUED)->get();

        return response()->json([
            'data' => $pools->map(fn (LicencePool $p) => BillingPresenter::pool($p))->all(),
            'past' => $past->map(fn (LicencePool $p) => BillingPresenter::pool($p))->all(),
            'account' => [
                'blocked' => LicenceOrders::isBlocked($this->tenant()),
                'open_invoices' => $open->count(),
                'owed_cents' => $open->sum(fn (Invoice $i) => $i->balanceCents()),
                'overdue_cents' => $open->filter(fn (Invoice $i) => $i->daysOverdue() > 0)->sum(fn (Invoice $i) => $i->balanceCents()),
                'payment_terms' => $this->tenant()->payment_terms,
            ],
        ]);
    }

    public function order(Request $request): JsonResponse
    {
        $data = $request->validate([
            'package' => ['required_without:pool', 'string'],
            'pool' => ['required_without:package', 'uuid'],
            'seats' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $actor = $this->actor($request);
        if (isset($data['pool'])) {
            $result = $this->orders->topUp($this->tenant(), LicencePool::query()->with('package')->findOrFail($data['pool']), (int) $data['seats'], $actor);
        } else {
            $package = Package::query()->where('code', $data['package'])->firstOrFail();
            $result = $this->orders->buy($this->tenant(), $package, (int) $data['seats'], $actor);
        }

        return response()->json([
            'invoice' => BillingPresenter::invoice($result['invoice']),
            'pool' => BillingPresenter::pool($result['order']->pool()->firstOrFail()),
        ], 201);
    }

    public function update(Request $request, LicencePool $pool): JsonResponse
    {
        $data = $request->validate(['seats_next_month' => ['required', 'integer', 'min:1', 'max:100000']]);
        $pool->load('package');

        return response()->json(['data' => BillingPresenter::pool($this->orders->setNextMonthSeats($pool, (int) $data['seats_next_month'], $this->actor($request)))]);
    }

    public function cancel(Request $request, LicencePool $pool): JsonResponse
    {
        $data = $request->validate(['cancel' => ['required', 'boolean']]);

        return response()->json(['data' => BillingPresenter::pool($this->orders->setCancelled($pool, (bool) $data['cancel'], $this->actor($request)))]);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return $this->context->tenant();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
