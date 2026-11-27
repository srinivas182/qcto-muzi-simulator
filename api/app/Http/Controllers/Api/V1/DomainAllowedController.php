<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "On-demand TLS" check for the edge server (Caddy / FrankenPHP): an HTTPS certificate is only
 * requested for platform hosts and verified tenant domains. Anything else gets 404, so nobody can
 * point a random domain at the platform and make it issue certificates.
 */
final class DomainAllowedController extends Controller
{
    public function __invoke(Request $request, TenantResolver $resolver): Response
    {
        $host = TenantResolver::normaliseHost((string) $request->query('domain', ''));

        $allowed = $host !== '' && ($resolver->isPlatformHost($host) || $resolver->byHost($host) !== null);

        return response('', $allowed ? 200 : 404);
    }
}
