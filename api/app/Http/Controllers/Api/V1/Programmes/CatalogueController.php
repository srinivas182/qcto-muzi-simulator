<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Programmes;

use App\Http\Controllers\Controller;
use App\Models\Programme;
use App\Models\Tenant;
use App\Programmes\ProgrammeCatalogue;
use App\Programmes\ProviderProgrammes;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** Provider view of the catalogue: published versions only, plus the provider's own checklist and additions. */
final class CatalogueController extends Controller
{
    public function __construct(
        private readonly ProgrammeCatalogue $catalogue,
        private readonly ProviderProgrammes $provider,
        private readonly TenantContext $context,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalogue->published()]);
    }

    public function show(Programme $programme): JsonResponse
    {
        $content = $programme->status === 'active' ? $this->catalogue->publishedVersion($programme) : null;
        abort_if($content === null, 404);
        /** @var Tenant $tenant */
        $tenant = $this->context->tenant();

        return response()->json(['data' => $content, 'provider' => $this->provider->view($programme, $tenant)]);
    }
}
