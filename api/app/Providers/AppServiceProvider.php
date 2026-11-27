<?php

declare(strict_types=1);

namespace App\Providers;

use App\Identity\Messaging\CodeDelivery;
use App\Identity\Messaging\CodeSender;
use App\Tenancy\Dns\DnsResolver;
use App\Tenancy\Dns\SystemDnsResolver;
use App\Tenancy\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh context per request / job, including on long-lived Octane workers.
        $this->app->scoped(TenantContext::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(CodeDelivery::class, CodeSender::class);
    }

    public function boot(): void
    {
        // Sign-in endpoints: generous for real people, hostile to scripted guessing.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));
    }
}
