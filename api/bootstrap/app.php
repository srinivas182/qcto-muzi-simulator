<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureConsented;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlatformStaff;
use App\Http\Middleware\EnsureProviderOpen;
use App\Http\Middleware\PlatformContext;
use App\Http\Middleware\ResolveSupportSession;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\WebTokenCookie;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'platform' => PlatformContext::class,
            'platform.staff' => EnsurePlatformStaff::class,
            'active' => EnsureActiveUser::class,
            'consented' => EnsureConsented::class,
            'permission' => EnsurePermission::class,
            'support' => ResolveSupportSession::class,
            'provider.open' => EnsureProviderOpen::class,
        ]);

        // The tenant/platform context must be set before authentication, so row-level security
        // decides whether a token's user is visible at this address at all.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveTenant::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: PlatformContext::class);
        // The web app's sign-in cookie becomes the bearer token before anything else looks for one.
        $middleware->api(prepend: WebTokenCookie::class);
        $middleware->prependToPriorityList(before: ResolveTenant::class, prepend: WebTokenCookie::class);
        // HQ support tokens are recognised after the tenant is known and before the user is loaded.
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveSupportSession::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
