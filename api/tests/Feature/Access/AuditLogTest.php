<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Audit\AuditLogger;
use App\Models\AuditLog;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class AuditLogTest extends AccessTestCase
{
    public function test_sign_ins_and_failures_are_recorded(): void
    {
        $this->userIn($this->ubuntu, ['email' => 'nomsa@ubuntuskills.co.za', 'name' => 'Nomsa Dlamini']);

        $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'wrong-password'])->assertStatus(422);
        $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery'])->assertOk();

        $this->assertAudited('auth.login_failed', ['tenant_id' => $this->ubuntu->id]);
        $this->assertAudited('auth.login', ['tenant_id' => $this->ubuntu->id, 'actor_name' => 'Nomsa Dlamini']);
    }

    public function test_a_provider_sees_only_its_own_audit_log(): void
    {
        [, $ubuntuAdmin] = $this->person($this->ubuntu, 'tp_admin');
        [, $leboneAdmin] = $this->person($this->lebone, 'tp_admin');
        app(TenantContext::class)->runAs($this->lebone, fn () => app(AuditLogger::class)->record('learners.exported', null, null, ['rows' => 40]));

        $actions = collect($this->getJson($this->url('/audit'), $this->bearer($ubuntuAdmin))->assertOk()->json('data'))->pluck('action');
        $this->assertContains('consent.granted', $actions);
        $this->assertNotContains('learners.exported', $actions);

        $this->fresh()->getJson($this->url('/audit?action=learners', 'lebone.simuwork.test'), $this->bearer($leboneAdmin))
            ->assertOk()->assertJsonPath('data.0.action', 'learners.exported');
    }

    public function test_only_people_with_audit_access_can_read_it(): void
    {
        [, $facilitator] = $this->person($this->ubuntu, 'facilitator');

        $this->getJson($this->url('/audit'), $this->bearer($facilitator))->assertForbidden();
    }

    public function test_hq_with_audit_access_sees_all_providers(): void
    {
        [, $hq] = $this->person(null, 'super_admin', ['email' => 'srini@mayuraconsultancy.com']);
        $this->person($this->ubuntu, 'tp_admin');
        $this->person($this->lebone, 'tp_admin');

        $tenants = collect($this->getJson($this->url('/platform/audit', self::HQ), $this->bearer($hq))->assertOk()->json('data'))
            ->pluck('tenant_id')->filter()->unique();

        $this->assertCount(2, $tenants);
    }

    public function test_hq_staff_without_audit_access_are_refused(): void
    {
        [, $designer] = $this->person(null, 'scenario_designer', ['email' => 'lwazi@mayuraconsultancy.com']);

        $this->getJson($this->url('/platform/audit', self::HQ), $this->bearer($designer))->assertForbidden();
    }

    public function test_entries_cannot_be_changed_or_deleted_even_by_hq(): void
    {
        $context = app(TenantContext::class);
        $entry = $context->runAs($this->ubuntu, fn () => app(AuditLogger::class)->record('roles.updated'));

        // Each attempt runs in a savepoint so the test transaction survives the database's refusal.
        foreach (['update', 'delete'] as $attempt) {
            $context->runAs(null, function () use ($entry, $attempt): void {
                try {
                    DB::transaction(fn () => $attempt === 'update'
                        ? AuditLog::query()->whereKey($entry->id)->update(['action' => 'nothing.happened'])
                        : AuditLog::query()->whereKey($entry->id)->delete());
                    $this->fail("Audit entries must not allow {$attempt}.");
                } catch (QueryException $e) {
                    $this->assertStringContainsString('append-only', $e->getMessage());
                }
            }, platform: true);
        }

        $this->assertAudited('roles.updated', ['id' => $entry->id]);
    }
}
