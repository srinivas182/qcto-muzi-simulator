<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            $user->tokens()->whereKey($user->currentAccessToken()->getKey())->delete();

            return response()->json(['message' => 'This account is disabled.'], 403);
        }

        return $next($request);
    }
}
