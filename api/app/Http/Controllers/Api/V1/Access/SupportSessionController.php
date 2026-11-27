<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Access;

use App\Http\Controllers\Controller;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\SupportSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SupportSessionController extends Controller
{
    public function __construct(private readonly SupportSessionService $sessions) {}

    /** HQ: open a read-only support view of a provider. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:500'],
            'minutes' => ['nullable', 'integer', 'min:5', 'max:'.SupportSessionService::MAX_MINUTES],
        ]);

        $tenant = Tenant::query()->findOrFail($data['tenant_id']);
        /** @var User $staff */
        $staff = $request->user();
        $result = $this->sessions->start($staff, $tenant, $data['reason'], (int) ($data['minutes'] ?? 30));
        $host = $tenant->domains()->where('type', TenantDomain::TYPE_SUBDOMAIN)->value('host');

        return response()->json(['data' => [
            'session' => $this->present($result['session']),
            'token' => $result['token'],
            'provider_host' => $host,
        ]], 201);
    }

    /** Provider: see every HQ support view of this account. */
    public function index(): JsonResponse
    {
        return response()->json(['data' => SupportSession::query()->latest()->limit(100)->get()->map(fn ($s) => $this->present($s))]);
    }

    /** HQ ends its own session, or the provider ends any session on its account. */
    public function destroy(Request $request, string $session): JsonResponse
    {
        $model = SupportSession::query()->findOrFail($session);
        /** @var User $actor */
        $actor = $request->user();
        $this->sessions->end($model, $actor->isPlatformStaff() ? 'staff' : 'provider', $actor);

        return response()->json(['message' => 'Support session ended.']);
    }

    /** @return array<string, mixed> */
    private function present(SupportSession $s): array
    {
        return [
            'id' => $s->id,
            'tenant_id' => $s->tenant_id,
            'staff_name' => $s->staff_name,
            'reason' => $s->reason,
            'expires_at' => $s->expires_at->toIso8601String(),
            'ended_at' => $s->ended_at?->toIso8601String(),
            'ended_by' => $s->ended_by,
            'active' => $s->isActive(),
        ];
    }
}
