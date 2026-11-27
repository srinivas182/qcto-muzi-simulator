<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\Role;
use App\Tenancy\TenantContext;

final class RolesApiTest extends AccessTestCase
{
    public function test_admin_sees_the_provider_roles_with_plain_language_permissions(): void
    {
        [, $token] = $this->person($this->ubuntu, 'tp_admin');

        $response = $this->getJson($this->url('/roles'), $this->bearer($token))->assertOk();

        $keys = collect($response->json('data'))->pluck('key')->sort()->values()->all();
        $this->assertSame(['assessor', 'centre_manager', 'facilitator', 'learner', 'moderator', 'qcto_reviewer', 'tp_admin'], $keys);
        $learner = collect($response->json('data'))->firstWhere('key', 'learner');
        $this->assertSame(['Use simulators and courses'], $learner['permission_labels']);
        $this->assertSame(1, collect($response->json('data'))->firstWhere('key', 'tp_admin')['people']);
    }

    public function test_permission_catalogue_has_only_provider_permissions(): void
    {
        [, $token] = $this->person($this->ubuntu, 'tp_admin');

        $keys = collect($this->getJson($this->url('/permissions'), $this->bearer($token))->assertOk()->json('data'))
            ->flatMap(fn (array $g) => array_column($g['permissions'], 'key'));

        $this->assertContains('learners.manage', $keys);
        $this->assertNotContains('packages.manage', $keys);
    }

    public function test_learners_cannot_manage_roles(): void
    {
        [, $token] = $this->person($this->ubuntu, 'learner');

        $this->getJson($this->url('/roles'), $this->bearer($token))->assertForbidden();
        $this->postJson($this->url('/roles'), ['name' => 'Sneaky', 'permissions' => ['billing.tenant']], $this->bearer($token))->assertForbidden();
    }

    public function test_custom_role_is_created_and_stays_with_its_provider(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        [, $other] = $this->person($this->lebone, 'tp_admin');

        $this->postJson($this->url('/roles'), [
            'name' => 'Attendance Clerk', 'description' => 'Marks registers', 'permissions' => ['attendance.mark', 'learners.view'],
        ], $this->bearer($admin))->assertCreated()->assertJsonPath('data.key', 'attendance_clerk')->assertJsonPath('data.is_system', false);

        $this->fresh()->getJson($this->url('/roles', 'lebone.simuwork.test'), $this->bearer($other))->assertOk()
            ->assertJsonMissing(['key' => 'attendance_clerk']);

        $this->assertAudited('roles.created', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_built_in_roles_cannot_be_changed_or_deleted(): void
    {
        [, $token] = $this->person($this->ubuntu, 'tp_admin');
        $id = $this->roleId('facilitator');

        $this->patchJson($this->url("/roles/{$id}"), ['name' => 'Teacher', 'permissions' => ['classes.view']], $this->bearer($token))
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->deleteJson($this->url("/roles/{$id}"), [], $this->bearer($token))->assertStatus(422);
    }

    public function test_hq_permissions_cannot_be_put_in_a_provider_role(): void
    {
        [, $token] = $this->person($this->ubuntu, 'tp_admin');

        $this->postJson($this->url('/roles'), ['name' => 'Publisher', 'permissions' => ['content.publish']], $this->bearer($token))
            ->assertStatus(422)->assertJsonValidationErrors('permissions');
    }

    public function test_nobody_can_grant_a_permission_they_do_not_hold(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        $editorRole = $this->postJson($this->url('/roles'), [
            'name' => 'Role Editor', 'permissions' => ['roles.manage_tenant', 'staff.manage'],
        ], $this->bearer($admin))->assertCreated()->json('data.id');

        [$editor, $editorToken] = $this->person($this->ubuntu, 'learner');
        app(TenantContext::class)->runAs($this->ubuntu, fn () => $editor->roles()->sync([$editorRole => ['tenant_id' => $this->ubuntu->id]]));

        $this->fresh()->postJson($this->url('/roles'), ['name' => 'Money', 'permissions' => ['billing.tenant']], $this->bearer($editorToken))
            ->assertStatus(422)->assertJsonPath('errors.permissions.0', 'You cannot give a permission you do not have yourself.');

        // …but may enrol people as learners (a functional permission, not an admin power).
        $this->postJson($this->url('/roles'), ['name' => 'Learner Plus', 'permissions' => ['learning.use', 'staff.manage']], $this->bearer($editorToken))
            ->assertCreated();
    }

    public function test_centre_manager_can_assign_facilitators_but_not_administrators(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        [, $manager] = $this->person($this->ubuntu, 'centre_manager');
        [$sipho] = $this->person($this->ubuntu, 'learner');

        $this->putJson($this->url("/users/{$sipho->id}/roles"), ['roles' => [['role_id' => $this->roleId('facilitator')]]], $this->bearer($manager))
            ->assertOk()->assertJsonPath('data.roles.0.key', 'facilitator');

        $this->fresh()->putJson($this->url("/users/{$sipho->id}/roles"), ['roles' => [['role_id' => $this->roleId('tp_admin')]]], $this->bearer($manager))
            ->assertStatus(422)->assertJsonValidationErrors('roles');

        $this->assertAudited('roles.assigned', ['subject_id' => $sipho->id]);
        $this->assertNotNull($admin);
    }

    public function test_hq_roles_cannot_be_assigned_inside_a_provider(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        [$sipho] = $this->person($this->ubuntu, 'learner');

        $this->putJson($this->url("/users/{$sipho->id}/roles"), ['roles' => [['role_id' => $this->roleId('super_admin')]]], $this->bearer($admin))
            ->assertStatus(422);
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        [$admin, $token] = $this->person($this->ubuntu, 'tp_admin');

        $this->putJson($this->url("/users/{$admin->id}/roles"), ['roles' => [['role_id' => $this->roleId('facilitator')]]], $this->bearer($token))
            ->assertStatus(422)->assertJsonPath('errors.roles.0', 'At least one person must remain a TP Administrator.');

        $this->assertTrue(app(TenantContext::class)->runAs($this->ubuntu, fn () => $admin->fresh()?->hasPermission('billing.tenant')));
    }

    public function test_people_in_another_provider_are_invisible(): void
    {
        [, $token] = $this->person($this->ubuntu, 'tp_admin');
        [$thandi] = $this->person($this->lebone, 'learner');

        $this->getJson($this->url("/users/{$thandi->id}/roles"), $this->bearer($token))->assertNotFound();
        $this->putJson($this->url("/users/{$thandi->id}/roles"), ['roles' => [['role_id' => $this->roleId('tp_admin')]]], $this->bearer($token))
            ->assertNotFound();
    }

    public function test_roles_in_use_cannot_be_deleted_but_unused_ones_can(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        [$sipho] = $this->person($this->ubuntu, 'learner');
        $id = $this->postJson($this->url('/roles'), ['name' => 'Clerk', 'permissions' => ['attendance.mark']], $this->bearer($admin))->json('data.id');

        $this->putJson($this->url("/users/{$sipho->id}/roles"), ['roles' => [['role_id' => $id]]], $this->bearer($admin))->assertOk();
        $this->deleteJson($this->url("/roles/{$id}"), [], $this->bearer($admin))->assertStatus(422);

        $this->putJson($this->url("/users/{$sipho->id}/roles"), ['roles' => [['role_id' => $this->roleId('learner')]]], $this->bearer($admin))->assertOk();
        $this->deleteJson($this->url("/roles/{$id}"), [], $this->bearer($admin))->assertOk();
    }

    public function test_preview_shows_what_a_role_would_see(): void
    {
        [, $token] = $this->person($this->ubuntu, 'tp_admin');

        $preview = $this->getJson($this->url('/roles/'.$this->roleId('facilitator').'/preview'), $this->bearer($token))->assertOk();

        $paths = array_column($preview->json('data.navigation'), 'path');
        $this->assertContains('/teach/attendance', $paths);
        $this->assertNotContains('/admin/billing', $paths);
        $this->assertNotContains('/learn', $paths);
        $this->assertContains('Mark attendance', $preview->json('data.permissions'));
    }

    public function test_gate_understands_catalogue_permissions(): void
    {
        [$facilitator] = $this->person($this->ubuntu, 'facilitator');

        app(TenantContext::class)->runAs($this->ubuntu, function () use ($facilitator): void {
            $this->assertTrue($facilitator->can('attendance.mark'));
            $this->assertFalse($facilitator->can('billing.tenant'));
        });
    }

    public function test_hq_manages_hq_roles_only(): void
    {
        [, $token] = $this->person(null, 'super_admin', ['email' => 'srini@mayuraconsultancy.com']);
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        $this->postJson($this->url('/roles'), ['name' => 'Clerk', 'permissions' => ['attendance.mark']], $this->bearer($admin))->assertCreated();

        $keys = collect($this->fresh()->getJson($this->url('/platform/roles', self::HQ), $this->bearer($token))->assertOk()->json('data'))->pluck('key');

        $this->assertContains('support_finance', $keys);
        $this->assertNotContains('tp_admin', $keys);
        $this->assertNotContains('clerk', $keys);

        $this->postJson($this->url('/platform/roles', self::HQ), ['name' => 'Finance Clerk', 'permissions' => ['billing.view']], $this->bearer($token))
            ->assertCreated()->assertJsonPath('data.scope', Role::SCOPE_PLATFORM);
    }
}
