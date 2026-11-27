<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Identity\InvitationService;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class UserInvite extends Command
{
    protected $signature = 'user:invite {tenant : Provider sub-domain} {name} {--phone=} {--email=} {--role=learner}';

    protected $description = 'Invite a person to a training provider and print the join link';

    public function handle(InvitationService $invitations, TenantContext $context): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('tenant'))->first();
        if ($tenant === null) {
            $this->error('Training provider not found.');

            return self::FAILURE;
        }

        try {
            $result = $context->runAs($tenant, fn () => $invitations->create(
                $tenant,
                (string) $this->argument('name'),
                (string) $this->option('role'),
                $this->option('phone') ? (string) $this->option('phone') : null,
                $this->option('email') ? (string) $this->option('email') : null,
            ));
        } catch (ValidationException $e) {
            $this->error((string) collect($e->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->info('Invitation created. Join link (valid '.config('identity.invitations.ttl_days').' days):');
        $this->line($result['url']);

        return self::SUCCESS;
    }
}
