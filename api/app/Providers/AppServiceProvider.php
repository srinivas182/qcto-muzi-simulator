<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\Dns\DnsResolver;
use App\Tenancy\Dns\SystemDnsResolver;
use App\Tenancy\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped: a fresh context per request / job, including on long-lived Octane workers.
        $this->app->scoped(TenantContext::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
    }

    public function boot(): void
    {
        //
    }
}
