<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Access\AuditController;
use App\Http\Controllers\Api\V1\Access\BrandingController;
use App\Http\Controllers\Api\V1\Access\RoleController;
use App\Http\Controllers\Api\V1\Access\SupportSessionController;
use App\Http\Controllers\Api\V1\Access\UserRoleController;
use App\Http\Controllers\Api\V1\Auth\AccountController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\Billing\PackageController;
use App\Http\Controllers\Api\V1\Billing\ShopController;
use App\Http\Controllers\Api\V1\CurrentTenantController;
use App\Http\Controllers\Api\V1\DomainAllowedController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Programmes\CatalogueController;
use App\Http\Controllers\Api\V1\Programmes\CoverageController;
use App\Http\Controllers\Api\V1\Programmes\PlatformProgrammeController;
use App\Http\Controllers\Api\V1\Programmes\ProgrammeStudioController;
use App\Http\Controllers\Api\V1\Programmes\ProviderProgrammeController;
use App\Http\Controllers\Api\V1\Programmes\ReferencePageController;
use App\Http\Controllers\Api\V1\Providers\AcademyController;
use App\Http\Controllers\Api\V1\Providers\ProviderController;
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
        Route::get('/session', SessionController::class)->name('session');
        Route::get('/auth/me', [AccountController::class, 'me'])->name('auth.me');
        Route::patch('/auth/preferences', [AccountController::class, 'preferences'])->name('auth.preferences');
        Route::post('/auth/logout', [AccountController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/consents', [AccountController::class, 'consent'])->name('auth.consents');
        Route::get('/auth/sessions', [AccountController::class, 'sessions'])->name('auth.sessions');
        Route::delete('/auth/sessions/{id}', [AccountController::class, 'revokeSession'])->name('auth.sessions.revoke');
        Route::post('/auth/two-factor', [TwoFactorController::class, 'enable'])->name('auth.two-factor.enable');
        Route::post('/auth/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('auth.two-factor.confirm');
        Route::delete('/auth/two-factor', [TwoFactorController::class, 'disable'])->name('auth.two-factor.disable');
    });
};

/** Roles, permissions and people's roles (a provider's, or HQ's own when $platform). */
$accessRoutes = function (bool $platform): void {
    $manage = $platform ? 'permission:roles.manage_platform' : 'permission:roles.manage_tenant';
    $view = $platform ? 'permission:roles.manage_platform' : 'permission:roles.manage_tenant|staff.manage';

    Route::get('/permissions', [RoleController::class, 'permissions'])->middleware($view)->name('permissions.index');
    Route::get('/roles', [RoleController::class, 'index'])->middleware($view)->name('roles.index');
    Route::post('/roles', [RoleController::class, 'store'])->middleware($manage)->name('roles.store');
    Route::patch('/roles/{role}', [RoleController::class, 'update'])->middleware($manage)->name('roles.update');
    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])->middleware($manage)->name('roles.destroy');
    Route::get('/roles/{role}/preview', [RoleController::class, 'preview'])->middleware($view)->name('roles.preview');
    Route::get('/users/{user}/roles', [UserRoleController::class, 'show'])->middleware($view)->name('users.roles.show');
    Route::put('/users/{user}/roles', [UserRoleController::class, 'update'])->middleware($platform ? $manage : 'permission:staff.manage')->name('users.roles.update');
};

Route::prefix('v1')->name('api.v1.')->group(function () use ($authRoutes, $accessRoutes): void {
    Route::get('/health', HealthController::class)->name('health');

    // Edge server asks this before issuing an HTTPS certificate for a host.
    Route::get('/internal/domain-allowed', DomainAllowedController::class)
        ->middleware('throttle:120,1')
        ->name('internal.domain-allowed');

    // Training provider context (provider sub-domain, custom domain, or X-Tenant on platform hosts).
    Route::middleware(['tenant', 'support'])->group(function () use ($authRoutes, $accessRoutes): void {
        Route::get('/tenant', CurrentTenantController::class)->name('tenant.current');

        Route::middleware('throttle:auth')->group(function (): void {
            Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
            Route::post('/invitations/{token}/code', [InvitationController::class, 'sendCode'])->name('invitations.code');
            Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');
        });

        $authRoutes(false);

        Route::middleware(['auth:sanctum', 'active', 'consented', 'provider.open'])->group(function () use ($accessRoutes): void {
            $accessRoutes(false);
            Route::get('/audit', [AuditController::class, 'index'])->middleware('permission:audit.view_tenant')->name('audit.index');
            Route::patch('/branding', [BrandingController::class, 'update'])->middleware('permission:branding.manage')->name('branding.update');
            Route::middleware('permission:licences.manage')->group(function (): void {
                Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
                Route::get('/shop/{package}/quote', [ShopController::class, 'quote'])->name('shop.quote');
            });
            Route::get('/academy', [AcademyController::class, 'show'])->middleware('permission:academy.manage')->name('academy.show');
            Route::patch('/academy', [AcademyController::class, 'update'])->middleware('permission:academy.manage')->name('academy.update');

            Route::middleware('permission:catalogue.view')->group(function (): void {
                Route::get('/programmes', [CatalogueController::class, 'index'])->name('programmes.index');
                Route::get('/programmes/{programme}', [CatalogueController::class, 'show'])->name('programmes.show');
                Route::get('/reference-pages', [ReferencePageController::class, 'index'])->name('reference-pages.index');
                Route::get('/reference-pages/{page}', [ReferencePageController::class, 'show'])->name('reference-pages.show');
            });
            // The provider's "to confirm" answers and own additions (additions also need HQ's switch).
            Route::prefix('/programmes/{programme}')->name('programmes.')->middleware('permission:catalogue.manage')->group(function (): void {
                Route::put('/checks/{key}', [ProviderProgrammeController::class, 'answer'])->where('key', '[0-9a-f]{40}')->name('checks.answer');
                Route::post('/additions/outcomes', [ProviderProgrammeController::class, 'addOutcome'])->name('additions.outcomes.store');
                Route::patch('/additions/outcomes/{id}', [ProviderProgrammeController::class, 'updateOutcome'])->whereUuid('id')->name('additions.outcomes.update');
                Route::delete('/additions/outcomes/{id}', [ProviderProgrammeController::class, 'removeOutcome'])->whereUuid('id')->name('additions.outcomes.destroy');
                Route::post('/additions/evidence', [ProviderProgrammeController::class, 'addEvidence'])->name('additions.evidence.store');
                Route::patch('/additions/evidence/{id}', [ProviderProgrammeController::class, 'updateEvidence'])->whereUuid('id')->name('additions.evidence.update');
                Route::delete('/additions/evidence/{id}', [ProviderProgrammeController::class, 'removeEvidence'])->whereUuid('id')->name('additions.evidence.destroy');
            });
            Route::get('/support-sessions', [SupportSessionController::class, 'index'])->middleware('permission:audit.view_tenant')->name('support-sessions.index');
            Route::delete('/support-sessions/{session}', [SupportSessionController::class, 'destroy'])->middleware('permission:audit.view_tenant')->name('support-sessions.destroy');
        });
    });

    // HQ (Super Admin and platform team) on platform hosts only.
    Route::prefix('platform')->name('platform.')->middleware('platform')->group(function () use ($authRoutes, $accessRoutes): void {
        $authRoutes(true);

        Route::middleware(['auth:sanctum', 'platform.staff', 'active'])->group(function () use ($accessRoutes): void {
            $accessRoutes(true);
            Route::get('/audit', [AuditController::class, 'index'])->middleware('permission:audit.view_all')->name('audit.index');

            Route::middleware('permission:programmes.view')->group(function (): void {
                Route::get('/programmes', [PlatformProgrammeController::class, 'index'])->name('programmes.index');
                Route::get('/programmes/{programme}', [PlatformProgrammeController::class, 'show'])->name('programmes.show');
                Route::get('/programmes/{programme}/versions/{number}', [PlatformProgrammeController::class, 'version'])->whereNumber('number')->name('programmes.version');
                Route::get('/programmes/{programme}/compare', [ProgrammeStudioController::class, 'compare'])->name('programmes.compare');
                Route::get('/coverage', [CoverageController::class, 'index'])->name('coverage.index');
                Route::get('/programmes/{programme}/coverage', [CoverageController::class, 'show'])->name('programmes.coverage');
                Route::get('/reference-pages', [ReferencePageController::class, 'index'])->name('reference-pages.index');
                Route::get('/reference-pages/{page}', [ReferencePageController::class, 'show'])->name('reference-pages.show');
            });
            Route::put('/programmes/{programme}/coverage/{code}', [CoverageController::class, 'update'])
                ->middleware('permission:programmes.manage|content.author')->name('programmes.coverage.update');

            Route::middleware('permission:packages.manage|billing.view')->group(function (): void {
                Route::get('/packages', [PackageController::class, 'index'])->name('packages.index');
                Route::get('/packages/{package}', [PackageController::class, 'show'])->name('packages.show');
            });
            Route::middleware('permission:packages.manage')->group(function (): void {
                Route::post('/packages', [PackageController::class, 'store'])->name('packages.store');
                Route::patch('/packages/{package}', [PackageController::class, 'update'])->name('packages.update');
                Route::delete('/packages/{package}', [PackageController::class, 'destroy'])->name('packages.destroy');
                Route::put('/packages/{package}/prices', [PackageController::class, 'prices'])->name('packages.prices');
                Route::post('/packages/{package}/publish', [PackageController::class, 'publish'])->name('packages.publish');
                Route::post('/packages/{package}/retire', [PackageController::class, 'retire'])->name('packages.retire');
                Route::post('/packages/{package}/duplicate', [PackageController::class, 'duplicate'])->name('packages.duplicate');
            });

            Route::get('/providers', [ProviderController::class, 'index'])->middleware('permission:providers.view')->name('providers.index');
            Route::get('/providers/{provider}', [ProviderController::class, 'show'])->whereUuid('provider')->middleware('permission:providers.view')->name('providers.show');
            Route::middleware('permission:providers.manage')->group(function (): void {
                Route::post('/providers', [ProviderController::class, 'store'])->name('providers.store');
                Route::patch('/providers/{provider}', [ProviderController::class, 'update'])->whereUuid('provider')->name('providers.update');
                Route::post('/providers/{provider}/status', [ProviderController::class, 'status'])->whereUuid('provider')->name('providers.status');
                Route::post('/providers/{provider}/invitations', [ProviderController::class, 'invite'])->whereUuid('provider')->name('providers.invite');
            });
            Route::patch('/providers/{provider}/programme-additions', [ProviderController::class, 'programmeAdditions'])
                ->whereUuid('provider')->middleware('permission:providers.manage')->name('providers.programme-additions');

            Route::get('/support-sessions', [SupportSessionController::class, 'index'])->middleware('permission:support.login')->name('support-sessions.index');
            Route::post('/support-sessions', [SupportSessionController::class, 'store'])->middleware('permission:support.login')->name('support-sessions.store');
            Route::delete('/support-sessions/{session}', [SupportSessionController::class, 'destroy'])->middleware('permission:support.login')->name('support-sessions.destroy');

            // Programme Studio: authors edit drafts, reviewers approve or send back, publishers publish.
            Route::prefix('/programmes/{programme}/draft')->name('programmes.draft.')->group(function (): void {
                Route::middleware('permission:programmes.manage|content.author')->group(function (): void {
                    Route::post('/', [ProgrammeStudioController::class, 'start'])->name('start');
                    Route::patch('/', [ProgrammeStudioController::class, 'update'])->name('update');
                    Route::delete('/', [ProgrammeStudioController::class, 'discard'])->name('discard');
                    Route::post('/duty-areas', [ProgrammeStudioController::class, 'addDutyArea'])->name('duty-areas.store');
                    Route::patch('/duty-areas/{number}', [ProgrammeStudioController::class, 'updateDutyArea'])->whereNumber('number')->name('duty-areas.update');
                    Route::delete('/duty-areas/{number}', [ProgrammeStudioController::class, 'removeDutyArea'])->whereNumber('number')->name('duty-areas.destroy');
                    Route::post('/duty-areas/{number}/outcomes', [ProgrammeStudioController::class, 'addOutcome'])->whereNumber('number')->name('outcomes.store');
                    Route::patch('/outcomes/{code}', [ProgrammeStudioController::class, 'updateOutcome'])->name('outcomes.update');
                    Route::delete('/outcomes/{code}', [ProgrammeStudioController::class, 'removeOutcome'])->name('outcomes.destroy');
                    Route::post('/duty-areas/{number}/evidence', [ProgrammeStudioController::class, 'addEvidence'])->whereNumber('number')->name('evidence.store');
                    Route::patch('/evidence/{id}', [ProgrammeStudioController::class, 'updateEvidence'])->whereUuid('id')->name('evidence.update');
                    Route::delete('/evidence/{id}', [ProgrammeStudioController::class, 'removeEvidence'])->whereUuid('id')->name('evidence.destroy');
                    Route::post('/submit', [ProgrammeStudioController::class, 'submit'])->name('submit');
                });
                Route::post('/approve', [ProgrammeStudioController::class, 'approve'])->middleware('permission:content.review')->name('approve');
                Route::post('/request-changes', [ProgrammeStudioController::class, 'requestChanges'])->middleware('permission:content.review')->name('request-changes');
                Route::post('/publish', [ProgrammeStudioController::class, 'publish'])->middleware('permission:content.publish')->name('publish');
            });
        });
    });
});
