<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Access;

use App\Branding\BrandingService;
use App\Http\Controllers\Controller;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BrandingController extends Controller
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly TenantContext $context,
    ) {}

    public function update(Request $request): JsonResponse
    {
        $hex = ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $data = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'logo_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'primary' => ['sometimes', ...$hex],
            'accent' => ['sometimes', ...$hex],
            'nav' => ['sometimes', ...$hex],
            'default_locale' => ['sometimes', Rule::in(array_keys((array) config('app.locales')))],
        ]);

        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);
        $this->branding->update($tenant, $data);

        return response()->json(['data' => $this->branding->present($tenant)]);
    }
}
