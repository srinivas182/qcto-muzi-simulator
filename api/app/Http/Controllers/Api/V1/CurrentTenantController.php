<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Branding\BrandingService;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Public, cacheable description of the training provider behind this address.
 * The web and mobile apps call it first to load branding before sign-in.
 */
final class CurrentTenantController extends Controller
{
    public function __invoke(TenantContext $context, BrandingService $branding): JsonResponse
    {
        $tenant = $context->tenant();
        abort_if($tenant === null, 404);

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'branding' => $branding->present($tenant),
                'locales' => config('app.locales'),
            ],
        ])->header('Cache-Control', 'public, max-age=60');
    }
}
