<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Authorization\PermissionRegistry;
use App\Authorization\RoleRegistry;
use App\Models\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The permission table from PRD v1.2 §4, enforced. */
final class RoleMatrixTest extends TestCase
{
    public function test_there_are_twelve_system_roles(): void
    {
        $this->assertCount(12, RoleRegistry::definitions());
    }

    public function test_every_role_permission_exists_and_matches_the_role_scope(): void
    {
        foreach (RoleRegistry::definitions() as $key => $role) {
            $expected = $role['scope'] === Role::SCOPE_PLATFORM ? 'platform' : 'tenant';
            foreach (RoleRegistry::permissionsFor($key) as $permission) {
                $this->assertTrue(PermissionRegistry::exists($permission), "{$key}: unknown {$permission}");
                $this->assertSame($expected, PermissionRegistry::scopeOf($permission), "{$key}: {$permission} is in the wrong scope");
            }
        }
    }

    /** @return iterable<string, array{string, list<string>, list<string>}> */
    public static function matrix(): iterable
    {
        yield 'super admin' => ['super_admin', ['programmes.manage', 'packages.manage', 'providers.manage', 'audit.view_all', 'support.login'], ['learners.view', 'learning.use']];
        yield 'content manager' => ['content_manager', ['programmes.manage', 'content.publish'], ['billing.view', 'support.login', 'providers.manage']];
        yield 'scenario designer' => ['scenario_designer', ['content.author'], ['content.publish', 'content.review']];
        yield 'sme reviewer' => ['sme_reviewer', ['content.review'], ['content.author', 'content.publish']];
        yield 'support and finance' => ['support_finance', ['support.login', 'billing.manage', 'support.tickets'], ['content.author', 'packages.manage']];
        yield 'tp admin' => ['tp_admin', ['learners.manage', 'staff.manage', 'roles.manage_tenant', 'branding.manage', 'billing.tenant', 'audit.view_tenant'], ['learning.use', 'review_pack.view', 'programmes.manage']];
        yield 'centre manager' => ['centre_manager', ['learners.manage', 'classes.manage', 'licences.manage'], ['billing.tenant', 'branding.manage', 'roles.manage_tenant']];
        yield 'facilitator' => ['facilitator', ['classes.manage', 'attendance.mark', 'live_classroom.run'], ['assessments.signoff', 'learners.manage', 'moderation.perform']];
        yield 'assessor' => ['assessor', ['assessments.review', 'assessments.signoff', 'attendance.confirm_workplace'], ['moderation.perform', 'learners.manage']];
        yield 'moderator' => ['moderator', ['moderation.perform', 'eisa.manage'], ['assessments.signoff', 'learners.manage']];
        yield 'learner' => ['learner', ['learning.use'], ['learners.view', 'reports.view', 'classes.view']];
        yield 'qcto reviewer' => ['qcto_reviewer', ['review_pack.view'], ['learners.view', 'reports.export', 'learning.use']];
    }

    /**
     * @param  list<string>  $can
     * @param  list<string>  $cannot
     */
    #[DataProvider('matrix')]
    public function test_role_permissions(string $role, array $can, array $cannot): void
    {
        $permissions = RoleRegistry::permissionsFor($role);

        foreach ($can as $permission) {
            $this->assertContains($permission, $permissions, "{$role} should have {$permission}");
        }
        foreach ($cannot as $permission) {
            $this->assertNotContains($permission, $permissions, "{$role} should not have {$permission}");
        }
    }

    public function test_learner_has_exactly_one_permission(): void
    {
        $this->assertSame(['learning.use'], RoleRegistry::permissionsFor('learner'));
    }
}
