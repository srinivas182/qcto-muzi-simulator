<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Billing\BillingPresenter;
use App\Billing\InvoicePdf;
use App\Billing\Payments;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\LicencePool;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/** HQ billing: dashboard, every provider's invoices, recording EFT payments and credit notes. */
final class PlatformBillingController extends Controller
{
    public function __construct(
        private readonly Payments $payments,
        private readonly InvoicePdf $pdf,
    ) {}

    public function summary(): JsonResponse
    {
        $monthStart = today()->startOfMonth();
        $open = Invoice::query()->where('status', Invoice::ISSUED)->get();
        $active = LicencePool::query()->with('programme')->where('status', LicencePool::ACTIVE)->get();
        $names = Tenant::query()->pluck('name', 'id');

        return response()->json(['data' => [
            'paid_this_month_cents' => (int) Payment::query()->where('paid_on', '>=', $monthStart)->sum('amount_cents'),
            'invoiced_this_month_cents' => (int) Invoice::query()->where('issue_date', '>=', $monthStart)->where('status', '!=', Invoice::CREDITED)->sum('total_cents'),
            'outstanding_cents' => $open->sum(fn (Invoice $i) => $i->balanceCents()),
            'overdue_cents' => $open->filter(fn (Invoice $i) => $i->daysOverdue() > 0)->sum(fn (Invoice $i) => $i->balanceCents()),
            'monthly_recurring_cents' => $active->where('billing', 'monthly')->where('cancel_at_period_end', false)
                ->sum(fn (LicencePool $p) => (int) ($p->seats_next_month ?? $p->seats) * $p->unit_price_cents),
            'active_seats' => (int) $active->sum('seats'),
            'seats_by_programme' => $active->groupBy('programme_id')->map(fn ($pools) => ['title' => $pools->first()->programme->title, 'prefix' => $pools->first()->programme->prefix, 'seats' => (int) $pools->sum('seats')])->sortByDesc('seats')->values()->all(),
            'blocked_providers' => $open->filter(fn (Invoice $i) => $i->daysOverdue() > 30)->pluck('tenant_id')->unique()->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? ''])->values()->all(),
        ]]);
    }

    public function invoices(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', 'in:issued,paid,credited,overdue'], 'tenant_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:60']]);
        $query = Invoice::query()
            ->when($filters['tenant_id'] ?? null, fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where('number', 'ilike', "%{$v}%"))
            ->when(($filters['status'] ?? null) === 'overdue', fn ($q) => $q->where('status', Invoice::ISSUED)->where('due_date', '<', today()))
            ->when(in_array($filters['status'] ?? null, ['issued', 'paid', 'credited'], true), fn ($q) => $q->where('status', $filters['status']));

        return response()->json(['data' => $query->latest('issue_date')->latest('number')->limit(300)->get()->map(fn (Invoice $i) => BillingPresenter::invoice($i))->all()]);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        return response()->json(['data' => BillingPresenter::invoice($invoice, true)]);
    }

    public function pdf(Invoice $invoice): Response
    {
        return InvoiceController::download($this->pdf, $invoice, false);
    }

    public function creditNotePdf(Invoice $invoice): Response
    {
        return InvoiceController::download($this->pdf, $invoice, true);
    }

    public function recordPayment(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'amount_cents' => ['required', 'integer', 'min:1'],
            'reference' => ['required', 'string', 'max:120'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $this->payments->recordEft($invoice, (int) $data['amount_cents'], (string) $data['reference'], Carbon::parse((string) $data['paid_on']), $this->actor($request));

        return $this->show($invoice->refresh());
    }

    public function credit(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->payments->credit($invoice, (string) $data['reason'], $this->actor($request));

        return $this->show($invoice->refresh());
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
