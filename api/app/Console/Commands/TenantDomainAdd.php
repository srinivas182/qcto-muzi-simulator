<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\DomainService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class TenantDomainAdd extends Command
{
    protected $signature = 'tenant:domain-add {slug : Training provider sub-domain} {host : Custom domain, e.g. learn.example.co.za}';

    protected $description = 'Connect a custom domain to a training provider and show the DNS record that proves ownership';

    public function handle(DomainService $domains): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->error('Training provider not found.');

            return self::FAILURE;
        }

        try {
            $domain = $domains->addCustomDomain($tenant, (string) $this->argument('host'));
        } catch (ValidationException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $record = $domains->verificationRecord($domain);
        $this->info("Add this DNS record, then run: php artisan tenant:domain-verify {$domain->host}");
        $this->table(['Type', 'Name', 'Value'], [[$record['type'], $record['name'], $record['value']]]);
        $this->line("Also point {$domain->host} (CNAME) to tenants.".config('tenancy.platform_domain').'.');

        return self::SUCCESS;
    }
}
