<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\CurrentTenantController;
use App\Http\Controllers\Api\V1\DomainAllowedController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Versioned API. Every route lives under /api/v1 so future versions can run side by side.
*/

/** Sign-in and account routes, shared by training providers and HQ (each in its own context). */
$authRoutes = function (bool $platform): void {
    Route::middleware('throttle:auth')->group(function () use ($platform): void {
        if (! $platform) {
            Route::post('/auth/otp/request', [LoginController::class, 'requestCode'])->name('auth.otp.request');
            Route::post('/auth/otp/verify', [LoginController::class, 'verifyCode'])->name('auth.otp.verify');
        }
        Route::post('/auth/login', [LoginController::class, 'password'])->name('auth.login');
        Route::post('/auth/two-factor/challenge', [LoginController::class, 'twoFactor'])->name('auth.two-factor.challenge');
        Route::post('/auth/password/forgot', [PasswordResetController::class, 'forgot'])->name('auth.password.forgot');
        Route::post('/auth/password/reset', [PasswordResetController::class, 'reset'])->name('auth.password.reset');
    });

    $signedIn = $platform ? ['auth:sanctum', 'platform.staff', 'active'] : ['auth:sanctum', 'active'];

    Route::middleware($signedIn)->group(function (): void {
        Route::get('/auth/me', [AccountController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AccountController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/consents', [AccountController::class, 'consent'])->name('auth.consents');
        Route::get('/auth/sessions', [AccountController::class, 'sessions'])->name('auth.sessions');
        Route::delete('/auth/sessions/{id}', [AccountController::class, 'revokeSession'])->name('auth.sessions.revoke');
        Route::post('/auth/two-factor', [TwoFactorController::class, 'enable'])->name('auth.two-factor.enable');
        Route::post('/auth/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('auth.two-factor.confirm');
        Route::delete('/auth/two-factor', [TwoFactorController::class, 'disable'])->name('auth.two-factor.disable');
    });
};

Route::prefix('v1')->name('api.v1.')->group(function () use ($authRoutes): void {
    Route::get('/health', HealthController::class)->name('health');

    // Edge server asks this before issuing an HTTPS certificate for a host.
    Route::get('/internal/domain-allowed', DomainAllowedController::class)
        ->middleware('throttle:120,1')
        ->name('internal.domain-allowed');

    // Training provider context (provider sub-domain, custom domain, or X-Tenant on platform hosts).
    Route::middleware('tenant')->group(function () use ($authRoutes): void {
        Route::get('/tenant', CurrentTenantController::class)->name('tenant.current');

        Route::middleware('throttle:auth')->group(function (): void {
            Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
            Route::post('/invitations/{token}/code', [InvitationController::class, 'sendCode'])->name('invitations.code');
            Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
        });

        $authRoutes(false);
    });

    // HQ (Super Admin and platform team) on platform hosts only.
    Route::prefix('platform')->name('platform.')->middleware('platform')->group(function () use ($authRoutes): void {
        $authRoutes(true);
    });
});
