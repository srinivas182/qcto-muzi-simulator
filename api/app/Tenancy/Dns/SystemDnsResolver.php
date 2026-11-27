<?php

declare(strict_types=1);

namespace App\Tenancy\Dns;

final class SystemDnsResolver implements DnsResolver
{
    public function txt(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        $values = [];
        foreach ($records as $record) {
            if (isset($record['txt']) && is_string($record['txt'])) {
                $values[] = trim($record['txt']);
            }
        }

        return $values;
    }
}
