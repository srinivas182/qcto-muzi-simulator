<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HQ routes (/api/v1/platform/*) only work on platform hosts (admin.<domain>, api.<domain>, …).
 * They run in platform context so Super Admin screens can see across tenants.
 */
final class PlatformContext
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->resolver->isPlatformHost(TenantResolver::normaliseHost($request->getHost()))) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $this->context->setPlatform();

        return $next($request);
    }

    public function terminate(): void
    {
        $this->context->clear();
    }
}
