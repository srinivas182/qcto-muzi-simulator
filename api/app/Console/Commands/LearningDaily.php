<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Assessment\CameraChecks;
use App\Learning\LearnerNudges;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Daily learning jobs: gentle reminders to learners who went quiet (Sprint 4.6b). Safe to run twice. */
final class LearningDaily extends Command
{
    protected $signature = 'learning:daily';

    protected $description = 'Send gentle reminders to quiet learners and delete test photos that are past their date';

    public function handle(LearnerNudges $nudges, CameraChecks $camera, TenantContext $context): int
    {
        $sent = 0;
        $purged = 0;
        foreach (Tenant::query()->whereIn('status', [Tenant::STATUS_ACTIVE, Tenant::STATUS_TRIAL])->get() as $tenant) {
            $sent += $context->runAs($tenant, fn (): int => $nudges->run($tenant));
        }
        foreach (Tenant::query()->get() as $tenant) { // test photos are deleted on time, whatever the provider's status
            $purged += $context->runAs($tenant, fn (): int => $camera->purge());
        }
        $this->info("Reminders sent: {$sent}. Test photos deleted: {$purged}.");

        return self::SUCCESS;
    }
}
