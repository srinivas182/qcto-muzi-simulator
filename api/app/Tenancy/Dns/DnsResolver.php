<?php

declare(strict_types=1);

namespace App\Tenancy\Dns;

interface DnsResolver
{
    /**
     * TXT record values published for a host.
     *
     * @return list<string>
     */
    public function txt(string $host): array;
}
