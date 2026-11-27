<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant context for the request from its host (or X-Tenant header on platform hosts).
 * Usage: ->middleware('tenant') requires a tenant; ->middleware('tenant:optional') does not.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant === null) {
            $this->context->clear();

            if ($mode !== 'optional') {
                return response()->json(['message' => 'Training provider not found for this address.'], 404);
            }
        } else {
            $this->context->setTenant($tenant);
        }

        return $next($request);
    }

    public function terminate(): void
    {
        // Never leak a tenant into the next request on a long-lived (Octane) worker.
        $this->context->clear();
    }
}
