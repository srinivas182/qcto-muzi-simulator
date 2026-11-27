<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Identity\ConsentService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POPIA: until the person has agreed to the current privacy notice and terms, only the
 * account, consent and sign-out endpoints work. The apps show the consent screen on 403 + code.
 */
final class EnsureConsented
{
    public function __construct(private readonly ConsentService $consents) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isSupportViewer()) {
            $outstanding = $this->consents->outstanding($user);
            if ($outstanding !== []) {
                return response()->json([
                    'message' => 'Please review and accept the privacy notice to continue.',
                    'code' => 'CONSENT_REQUIRED',
                    'consent_required' => $outstanding,
                ], 403);
            }
        }

        return $next($request);
    }
}
