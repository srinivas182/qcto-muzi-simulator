<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Billing\Gateways\GatewayRegistry;
use App\Billing\OnlinePayments;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** Provider: pay an invoice online; and the public endpoint that receives gateway notifications. */
final class OnlinePaymentController extends Controller
{
    public function __construct(
        private readonly OnlinePayments $online,
        private readonly GatewayRegistry $gateways,
        private readonly TenantContext $context,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json(['data' => $this->gateways->enabled()->map(fn (PaymentGateway $g) => [
            'driver' => $g->driver, 'label' => $this->gateways->driver($g->driver)->label(), 'test_mode' => ! $g->isLive(),
        ])->all()]);
    }

    public function pay(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['gateway' => ['required', 'string'], 'return_url' => ['required', 'url', 'max:500']]);
        $this->assertOwnAddress((string) $data['return_url']);
        /** @var User $actor */
        $actor = $request->user();
        $result = $this->online->start($invoice, (string) $data['gateway'], (string) $data['return_url'], $actor);

        return response()->json(['attempt_id' => $result['attempt']->id, 'checkout' => $result['checkout']], 201);
    }

    /** Called when the provider comes back from the gateway: confirms with the gateway if still open. */
    public function attempt(PaymentAttempt $attempt): JsonResponse
    {
        if ($attempt->status === 'started') {
            $this->online->confirm($attempt);
            $attempt->refresh();
        }

        return response()->json(['data' => ['id' => $attempt->id, 'status' => $attempt->status, 'gateway' => $attempt->gateway, 'invoice_id' => $attempt->invoice_id, 'amount_cents' => $attempt->amount_cents]]);
    }

    public function notify(Request $request, string $driver): Response
    {
        return $this->online->notify($driver, $request) ? response('OK', 200) : response('Invalid signature', 400);
    }

    /** The return address must be one of this academy's own web addresses. */
    private function assertOwnAddress(string $url): void
    {
        /** @var Tenant $tenant */
        $tenant = $this->context->tenant();
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (! $tenant->domains()->where('host', $host)->exists()) {
            throw ValidationException::withMessages(['return_url' => 'The return address must be one of your academy’s addresses.']);
        }
    }
}
