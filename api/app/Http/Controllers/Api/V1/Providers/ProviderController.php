<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Providers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\ProviderAdmin;
use App\Tenancy\ProviderPresenter;
use App\Tenancy\ProviderSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HQ management of training providers: list, onboarding, details, status and per-provider switches. */
final class ProviderController extends Controller
{
    public function __construct(
        private readonly ProviderSettings $settings,
        private readonly ProviderAdmin $admin,
        private readonly ProviderPresenter $presenter,
    ) {}

    public function index(): JsonResponse
    {
        $providers = Tenant::query()->orderBy('name')->get()->map(fn (Tenant $t) => $this->presenter->summary($t));

        return response()->json(['data' => $providers]);
    }

    public function show(Tenant $provider): JsonResponse
    {
        return response()->json(['data' => $this->presenter->forHq($provider)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'slug' => ['nullable', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/'],
            'admin_name' => ['required', 'string', 'min:2', 'max:120'],
            'admin_email' => ['required', 'email:rfc', 'max:190'],
            ...$this->detailRules(),
        ]);
        $result = $this->admin->create($data, (string) $data['admin_name'], (string) $data['admin_email'], $this->actor($request));

        return response()->json(['data' => $this->presenter->forHq($result['tenant']), 'invite_url' => $result['invite_url']], 201);
    }

    public function update(Request $request, Tenant $provider): JsonResponse
    {
        $data = $request->validate($this->detailRules());
        $this->admin->update($provider, $data, [...ProviderAdmin::HQ_FIELDS, ...ProviderAdmin::PROVIDER_FIELDS], $this->actor($request));

        return $this->show($provider->refresh());
    }

    public function status(Request $request, Tenant $provider): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Tenant::STATUSES)],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $this->admin->changeStatus($provider, (string) $data['status'], (string) $data['reason'], $this->actor($request));

        return $this->show($provider->refresh());
    }

    public function invite(Request $request, Tenant $provider): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:120'], 'email' => ['required', 'email:rfc', 'max:190']]);
        $url = $this->admin->inviteAdmin($provider, (string) $data['name'], (string) $data['email'], $this->actor($request));

        return response()->json(['invite_url' => $url], 201);
    }

    public function programmeAdditions(Request $request, Tenant $provider): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $this->settings->setProgrammeAdditions($provider, (bool) $data['enabled'], $this->actor($request));

        return response()->json(['data' => $this->presenter->summary($provider->refresh())]);
    }

    /** @return array<string, list<mixed>> */
    private function detailRules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:3', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:190'],
            'registration_number' => ['nullable', 'string', 'max:40'],
            'vat_number' => ['nullable', 'string', 'regex:/^4\d{9}$/'],
            'accreditation_number' => ['nullable', 'string', 'max:40'],
            'accreditation_expires_at' => ['nullable', 'date'],
            'payment_terms' => ['sometimes', 'array'],
            'payment_terms.mode' => ['required_with:payment_terms', Rule::in(['pay_first', 'on_account'])],
            'payment_terms.days' => ['required_with:payment_terms', 'integer', 'min:0', 'max:90'],
            ...AcademyController::contactRules(),
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
