<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A provider's token must never work on HQ routes, even though platform context can see all users. */
final class EnsurePlatformStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Support tokens (ability "support") only work on the provider they were opened for.
        if (! $user instanceof User || ! $user->isPlatformStaff() || ! $user->currentAccessToken()->can('*')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return $next($request);
    }
}
