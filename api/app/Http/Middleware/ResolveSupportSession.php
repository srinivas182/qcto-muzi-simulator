<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\SupportSession;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Recognises HQ support tokens on provider routes. A support token works only for the provider its
 * session was opened for, only while the session is active, and only for reading (GET/HEAD).
 * Runs after the tenant is resolved and before authentication.
 */
final class ResolveSupportSession
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $token = $plain !== null ? PersonalAccessToken::findToken($plain) : null;

        if ($token === null || ! in_array('support', (array) $token->abilities, true)) {
            return $next($request);
        }

        // Row-level security: only sessions for the current provider are visible here.
        $session = SupportSession::query()->where('token_id', $token->getKey())->first();

        if ($session === null || ! $session->isActive()) {
            return response()->json(['message' => 'This support session has ended.'], 401);
        }

        if (! $request->isMethodSafe()) {
            return response()->json(['message' => 'Support sessions are read-only.'], 403);
        }

        $this->context->setSupportUser($session->staff_user_id);
        $request->attributes->set('support_session', $session);

        return $next($request);
    }
}
