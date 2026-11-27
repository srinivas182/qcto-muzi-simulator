<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Authorization\Navigation;
use App\Authorization\PermissionRegistry;
use App\Branding\BrandingService;
use App\Http\Controllers\Controller;
use App\Identity\Authenticator;
use App\Models\SupportSession;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the app shell needs after sign-in, in one request: who you are, what you may do,
 * your menu, the provider's branding and any active HQ support view.
 */
final class SessionController extends Controller
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly TenantContext $context,
        private readonly BrandingService $branding,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenant = $this->context->tenant();
        $support = $request->attributes->get('support_session');

        if ($support instanceof SupportSession) {
            $area = 'tenant';
            $permissions = array_values(array_filter(
                PermissionRegistry::keys('tenant'),
                fn (string $p) => ! in_array($p, ['learning.use', 'review_pack.view', 'attendance.confirm_workplace'], true),
            ));
        } else {
            $area = $this->context->isPlatform() ? 'platform' : 'tenant';
            $permissions = $user->permissions();
        }

        return response()->json(['data' => [
            'area' => $area,
            'user' => $this->auth->present($user),
            'permissions' => $permissions,
            'navigation' => Navigation::for($area, $permissions),
            'tenant' => $tenant === null ? null : ['id' => $tenant->id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'branding' => $this->branding->present($tenant)],
            'support' => $support instanceof SupportSession ? [
                'id' => $support->id,
                'staff_name' => $support->staff_name,
                'reason' => $support->reason,
                'expires_at' => $support->expires_at->toIso8601String(),
            ] : null,
            'locales' => config('app.locales'),
        ]]);
    }
}
