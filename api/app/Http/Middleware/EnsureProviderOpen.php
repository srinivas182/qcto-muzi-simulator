<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended provider's staff get "account on hold" (423) on provider routes. Learners keep learning
 * (they are not at fault), and HQ support sessions still work so HQ can help.
 */
final class EnsureProviderOpen
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();
        $user = $request->user();

        if ($tenant !== null && $tenant->isSuspended() && $user instanceof User && ! $user->isSupportViewer() && self::isStaff($user)) {
            return response()->json([
                'message' => 'Your academy’s account is on hold. Contact SimuWork HQ.',
                'code' => 'provider_on_hold',
            ], 423);
        }

        return $next($request);
    }

    /** Anyone with a permission beyond using simulators and courses. */
    public static function isStaff(User $user): bool
    {
        return array_diff($user->permissions(), ['learning.use']) !== [];
    }
}
