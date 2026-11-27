<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\DomainService;
use App\Tenancy\TenantResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TenantCreate extends Command
{
    protected $signature = 'tenant:create {name : Training provider name} {--slug= : Sub-domain (defaults to a slug of the name)}';

    protected $description = 'Create a training provider with its platform sub-domain';

    public function handle(DomainService $domains): int
    {
        $name = (string) $this->argument('name');
        $slug = Str::slug((string) ($this->option('slug') ?: $name));

        if (in_array($slug, TenantResolver::reservedSubdomains(), true) || Tenant::query()->where('slug', $slug)->exists()) {
            $this->error("The sub-domain '{$slug}' is not available.");

            return self::FAILURE;
        }

        $tenant = DB::transaction(function () use ($name, $slug, $domains): Tenant {
            $tenant = Tenant::query()->create(['name' => $name, 'slug' => $slug]);
            $domains->createSubdomain($tenant);

            return $tenant;
        });

        $this->info("Created {$tenant->name} → https://{$slug}.".TenantResolver::platformDomain());

        return self::SUCCESS;
    }
}
