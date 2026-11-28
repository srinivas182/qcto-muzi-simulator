<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Providers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\ProviderAdmin;
use App\Tenancy\ProviderPresenter;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The provider's own "Academy details": it sees everything HQ holds and edits its contact and billing details. */
final class AcademyController extends Controller
{
    public function __construct(
        private readonly ProviderAdmin $admin,
        private readonly ProviderPresenter $presenter,
        private readonly TenantContext $context,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->presenter->details($this->tenant())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(self::contactRules());
        /** @var User $actor */
        $actor = $request->user();
        $this->admin->update($this->tenant(), $data, ProviderAdmin::PROVIDER_FIELDS, $actor);

        return response()->json(['data' => $this->presenter->details($this->tenant()->refresh())]);
    }

    /** @return array<string, list<string>> */
    public static function contactRules(): array
    {
        return [
            'contact_email' => ['nullable', 'email:rfc', 'max:190'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'billing_email' => ['nullable', 'email:rfc', 'max:190'],
            'address' => ['sometimes', 'array'],
            'address.line1' => ['nullable', 'string', 'max:120'],
            'address.line2' => ['nullable', 'string', 'max:120'],
            'address.city' => ['nullable', 'string', 'max:80'],
            'address.province' => ['nullable', 'string', 'max:40'],
            'address.postal_code' => ['nullable', 'string', 'regex:/^\d{4}$/'],
        ];
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return $this->context->tenant();
    }
}
