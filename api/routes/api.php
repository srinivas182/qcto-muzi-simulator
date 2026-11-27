<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CurrentTenantController;
use App\Http\Controllers\Api\V1\DomainAllowedController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Versioned API. Every route lives under /api/v1 so future versions can run side by side.
*/
Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    // Edge server asks this before issuing an HTTPS certificate for a host.
    Route::get('/internal/domain-allowed', DomainAllowedController::class)
        ->middleware('throttle:120,1')
        ->name('internal.domain-allowed');

    // Routes that run inside a training provider's context.
    Route::middleware('tenant')->group(function (): void {
        Route::get('/tenant', CurrentTenantController::class)->name('tenant.current');
    });
});
