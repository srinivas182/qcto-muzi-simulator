<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Learning\LearnerNudges;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Daily learning jobs: gentle reminders to learners who went quiet (Sprint 4.6b). Safe to run twice. */
final class LearningDaily extends Command
{
    protected $signature = 'learning:daily';

    protected $description = 'Send gentle reminders to learners who have been quiet for a week';

    public function handle(LearnerNudges $nudges, TenantContext $context): int
    {
        $sent = 0;
        foreach (Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get() as $tenant) {
            $sent += $context->runAs($tenant, fn (): int => $nudges->run($tenant));
        }
        $this->info("Reminders sent: {$sent}.");

        return self::SUCCESS;
    }
}
