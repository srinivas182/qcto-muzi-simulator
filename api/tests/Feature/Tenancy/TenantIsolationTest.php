<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Centre;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Proves one training provider can never read or change another provider's data —
 * at the application layer (global scope) AND at the database layer (row-level security).
 */
final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $context;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = app(TenantContext::class);
        $this->a = Tenant::factory()->create(['name' => 'Ubuntu Skills Academy']);
        $this->b = Tenant::factory()->create(['name' => 'Lebone Tech Institute']);

        $this->context->runAs($this->a, fn () => Centre::factory()->count(2)->create());
        $this->context->runAs($this->b, fn () => Centre::factory()->count(3)->create());
        $this->context->clear();
    }

    public function test_database_user_is_not_a_superuser_so_rls_applies(): void
    {
        $isSuper = DB::selectOne('select rolsuper from pg_roles where rolname = current_user')->rolsuper;

        $this->assertFalse((bool) $isSuper, 'Row-level security is bypassed for superusers. Connect as a normal role.');
    }

    public function test_eloquent_returns_only_the_current_tenants_rows(): void
    {
        $this->context->setTenant($this->a);

        $this->assertCount(2, Centre::all());
        $this->assertTrue(Centre::all()->every(fn (Centre $c) => $c->tenant_id === $this->a->id));
    }

    public function test_database_blocks_other_tenants_rows_even_without_the_eloquent_scope(): void
    {
        $this->context->setTenant($this->a);

        $this->assertSame(2, DB::table('centres')->count());
        $this->assertSame(2, Centre::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('centres')->where('tenant_id', $this->b->id)->count());
    }

    public function test_no_context_means_no_rows(): void
    {
        $this->assertSame(0, DB::table('centres')->count());
        $this->assertCount(0, Centre::all());
    }

    public function test_platform_context_sees_all_tenants(): void
    {
        $this->context->setPlatform();

        $this->assertSame(5, DB::table('centres')->count());
        $this->assertCount(5, Centre::all());
    }

    public function test_cannot_insert_a_row_for_another_tenant(): void
    {
        $this->context->setTenant($this->a);

        $this->expectException(QueryException::class);

        DB::table('centres')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->b->id,
            'name' => 'Sneaky centre',
            'geofence_radius_m' => 150,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_cannot_update_or_delete_another_tenants_rows(): void
    {
        $this->context->setTenant($this->a);

        $this->assertSame(0, DB::table('centres')->where('tenant_id', $this->b->id)->update(['name' => 'Hacked']));
        $this->assertSame(0, DB::table('centres')->where('tenant_id', $this->b->id)->delete());

        $this->context->setTenant($this->b);
        $this->assertSame(0, DB::table('centres')->where('name', 'Hacked')->count());
        $this->assertSame(3, DB::table('centres')->count());
    }

    public function test_new_rows_get_the_current_tenant_automatically(): void
    {
        $this->context->setTenant($this->b);

        $centre = Centre::query()->create(['name' => 'Durban campus', 'code' => 'DBN']);

        $this->assertSame($this->b->id, $centre->tenant_id);
    }

    public function test_creating_tenant_data_without_a_context_is_refused(): void
    {
        $this->expectException(LogicException::class);

        Centre::query()->create(['name' => 'Orphan centre']);
    }

    public function test_run_as_restores_the_previous_context(): void
    {
        $this->context->setTenant($this->a);

        $countForB = $this->context->runAs($this->b, fn () => DB::table('centres')->count());

        $this->assertSame(3, $countForB);
        $this->assertSame($this->a->id, $this->context->tenantId());
        $this->assertSame(2, DB::table('centres')->count());
    }
}
