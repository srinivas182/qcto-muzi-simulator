<?php

declare(strict_types=1);

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL row-level security helpers for tenant-owned tables.
 *
 * Policy: a row is visible/writable only when its tenant_id equals the request's tenant
 * (session setting app.tenant_id), or when the request runs in platform context
 * (app.platform_access = 'on', used by the Super Admin). With neither set, nothing is visible:
 * the database fails closed even if application code forgets a filter.
 *
 * FORCE is used so the policy also applies to the table owner. The application must connect
 * as a non-superuser role (superusers bypass RLS); TenantIsolationTest guards this.
 */
final class Rls
{
    public static function enable(string $table): void
    {
        $condition = "(tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid"
            ." OR current_setting('app.platform_access', true) = 'on')";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("CREATE POLICY tenant_isolation ON {$table} USING {$condition} WITH CHECK {$condition}");
    }

    public static function disable(string $table): void
    {
        DB::statement("DROP POLICY IF EXISTS tenant_isolation ON {$table}");
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }
}
