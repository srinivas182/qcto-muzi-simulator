<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TenantDomain;
use App\Tenancy\DomainService;
use App\Tenancy\TenantResolver;
use Illuminate\Console\Command;

final class TenantDomainVerify extends Command
{
    protected $signature = 'tenant:domain-verify {host? : Domain to check; omit to check all unverified custom domains}';

    protected $description = 'Verify custom domains by checking their DNS TXT record';

    public function handle(DomainService $domains): int
    {
        $query = TenantDomain::query()->where('type', TenantDomain::TYPE_CUSTOM)->whereNull('verified_at');

        if ($this->argument('host')) {
            $query->where('host', TenantResolver::normaliseHost((string) $this->argument('host')));
        }

        $pending = $query->get();

        if ($pending->isEmpty()) {
            $this->info('No unverified custom domains to check.');

            return self::SUCCESS;
        }

        foreach ($pending as $domain) {
            $ok = $domains->verify($domain);
            $ok ? $this->info("✓ {$domain->host} verified") : $this->warn("… {$domain->host}: TXT record not found yet");
        }

        return self::SUCCESS;
    }
}
