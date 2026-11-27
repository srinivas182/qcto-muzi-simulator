<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Providers;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\ProviderSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** HQ list of training providers and their per-provider switches. Full provider management follows in Sprint 3. */
final class ProviderController extends Controller
{
    public function __construct(private readonly ProviderSettings $settings) {}

    public function index(): JsonResponse
    {
        $providers = Tenant::query()->orderBy('name')->get()->map(fn (Tenant $t) => $this->present($t));

        return response()->json(['data' => $providers]);
    }

    public function programmeAdditions(Request $request, Tenant $provider): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        /** @var User $actor */
        $actor = $request->user();
        $this->settings->setProgrammeAdditions($provider, (bool) $data['enabled'], $actor);

        return response()->json(['data' => $this->present($provider->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'programme_additions' => $tenant->allowsProgrammeAdditions(),
        ];
    }
}
