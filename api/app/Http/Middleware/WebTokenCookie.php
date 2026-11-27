<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web sign-in without tokens in JavaScript.
 *
 * When the web app signs in (client=web), the token is moved out of the response body into an
 * HttpOnly, Secure, SameSite=Lax cookie that only this host receives (so each academy's domain
 * has its own sign-in). On later requests the cookie becomes the bearer token.
 *
 * CSRF: cookie-authenticated writes must carry "X-Requested-With: XMLHttpRequest". Other sites
 * cannot add that header without a CORS pre-flight, which this API does not allow.
 * Mobile apps keep using bearer tokens and are unaffected.
 */
final class WebTokenCookie
{
    public const NAME = 'sw_session';

    public function handle(Request $request, Closure $next): Response
    {
        $fromCookie = false;
        $cookie = $request->cookies->get(self::NAME);

        if ($request->bearerToken() === null && is_string($cookie) && $cookie !== '') {
            if (! $request->isMethodSafe() && $request->header('X-Requested-With') !== 'XMLHttpRequest') {
                return response()->json(['message' => 'Request blocked. Please reload the page.', 'code' => 'CSRF'], 419);
            }
            $request->headers->set('Authorization', 'Bearer '.$cookie);
            $fromCookie = true;
        }

        $response = $next($request);

        if ($response instanceof JsonResponse && $request->input('client', 'web') !== 'mobile' && $request->header('X-Client') === 'web') {
            $data = $response->getData(true);
            if (is_array($data) && isset($data['token']) && is_string($data['token'])) {
                $expires = isset($data['expires_at']) && is_string($data['expires_at']) ? strtotime($data['expires_at']) : 0;
                $response->headers->setCookie($this->cookie($request, $data['token'], $expires ?: 0));
                unset($data['token']);
                $data['token_type'] = 'cookie';
                $response->setData($data);
            }
        }

        if ($fromCookie && ($response->getStatusCode() === 401 || $request->routeIs('api.v1.auth.logout', 'api.v1.platform.auth.logout'))) {
            $response->headers->setCookie($this->cookie($request, '', 1));
        }

        return $response;
    }

    private function cookie(Request $request, string $value, int $expires): Cookie
    {
        return Cookie::create(self::NAME, $value, $expires, '/api', null, $request->isSecure() || app()->isProduction(), true, false, Cookie::SAMESITE_LAX);
    }
}
