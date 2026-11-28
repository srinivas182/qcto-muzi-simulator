<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Billing;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Super Admin: the seller details, EFT banking details, VAT rate and numbering printed on invoices. */
final class BillingSettingsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present(BillingSetting::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_name' => ['nullable', 'string', 'max:190'],
            'registration_number' => ['nullable', 'string', 'max:40'],
            'vat_number' => ['nullable', 'string', 'regex:/^4\d{9}$/'],
            'address' => ['sometimes', 'array'],
            'address.line1' => ['nullable', 'string', 'max:120'],
            'address.line2' => ['nullable', 'string', 'max:120'],
            'address.city' => ['nullable', 'string', 'max:80'],
            'address.province' => ['nullable', 'string', 'max:40'],
            'address.postal_code' => ['nullable', 'string', 'regex:/^\d{4}$/'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'bank' => ['sometimes', 'array'],
            'bank.bank' => ['nullable', 'string', 'max:80'],
            'bank.account_name' => ['nullable', 'string', 'max:120'],
            'bank.account_number' => ['nullable', 'string', 'regex:/^\d{6,16}$/'],
            'bank.branch_code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'bank.account_type' => ['nullable', 'string', 'max:40'],
            'vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'invoice_prefix' => ['sometimes', 'string', 'max:12', 'regex:/^[A-Z0-9-]+$/'],
            'credit_note_prefix' => ['sometimes', 'string', 'max:12', 'regex:/^[A-Z0-9-]+$/', 'different:invoice_prefix'],
            'due_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $settings = BillingSetting::current();
        $before = $settings->only(array_keys($data));
        $settings->fill($data + ['updated_by' => $actor->id])->save();
        $this->context->runAs(null, fn () => $this->audit->record('billing.settings_changed', $settings, ['before' => $before, 'after' => $data], [], $actor), platform: true);

        return $this->show();
    }

    /** @return array<string, mixed> */
    private function present(BillingSetting $s): array
    {
        return [
            'seller_name' => $s->seller_name, 'registration_number' => $s->registration_number, 'vat_number' => $s->vat_number,
            'address' => (object) $s->address, 'email' => $s->email, 'phone' => $s->phone, 'bank' => (object) $s->bank,
            'vat_rate' => $s->vatRate(), 'invoice_prefix' => $s->invoice_prefix, 'credit_note_prefix' => $s->credit_note_prefix, 'due_days' => $s->due_days,
            'complete' => $s->seller_name !== null && $s->vat_number !== null && ($s->bank['account_number'] ?? null) !== null,
        ];
    }
}
