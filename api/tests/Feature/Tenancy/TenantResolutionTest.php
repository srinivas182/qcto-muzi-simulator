<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Tenancy\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.platform_domain' => 'simuwork.test']);
        $this->tenant = Tenant::factory()->create(['name' => 'Ubuntu Skills Academy', 'slug' => 'ubuntu']);
        app(DomainService::class)->createSubdomain($this->tenant);
    }

    public function test_tenant_resolves_from_its_platform_subdomain(): void
    {
        $this->getJson('http://ubuntu.simuwork.test/api/v1/tenant')
            ->assertOk()
            ->assertJsonPath('data.slug', 'ubuntu')
            ->assertJsonPath('data.name', 'Ubuntu Skills Academy')
            ->assertHeader('Cache-Control', 'max-age=60, public');
    }

    public function test_host_matching_is_case_insensitive(): void
    {
        $this->getJson('http://UBUNTU.SimuWork.test/api/v1/tenant')->assertOk()->assertJsonPath('data.slug', 'ubuntu');
    }

    public function test_verified_custom_domain_resolves(): void
    {
        $this->tenant->domains()->create([
            'host' => 'learn.ubuntuskills.co.za', 'type' => TenantDomain::TYPE_CUSTOM, 'verified_at' => now(),
        ]);

        $this->getJson('http://learn.ubuntuskills.co.za/api/v1/tenant')->assertOk()->assertJsonPath('data.slug', 'ubuntu');
    }

    public function test_unverified_custom_domain_does_not_resolve(): void
    {
        $this->tenant->domains()->create(['host' => 'learn.ubuntuskills.co.za', 'type' => TenantDomain::TYPE_CUSTOM]);

        $this->getJson('http://learn.ubuntuskills.co.za/api/v1/tenant')->assertNotFound();
    }

    public function test_unknown_host_is_not_found(): void
    {
        $this->getJson('http://nobody.simuwork.test/api/v1/tenant')->assertNotFound();
        $this->getJson('http://random-site.example/api/v1/tenant')->assertNotFound();
    }

    public function test_reserved_platform_hosts_need_the_tenant_header(): void
    {
        $this->getJson('http://api.simuwork.test/api/v1/tenant')->assertNotFound();

        $this->getJson('http://api.simuwork.test/api/v1/tenant', ['X-Tenant' => 'ubuntu'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'ubuntu');
    }

    public function test_header_cannot_select_a_reserved_slug(): void
    {
        $this->getJson('http://api.simuwork.test/api/v1/tenant', ['X-Tenant' => 'admin'])->assertNotFound();
    }

    public function test_header_is_ignored_on_a_tenant_host(): void
    {
        $other = Tenant::factory()->create(['slug' => 'lebone']);

        $this->getJson('http://ubuntu.simuwork.test/api/v1/tenant', ['X-Tenant' => $other->slug])
            ->assertOk()
            ->assertJsonPath('data.slug', 'ubuntu');
    }

    public function test_archived_tenant_is_not_reachable(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_ARCHIVED]);

        $this->getJson('http://ubuntu.simuwork.test/api/v1/tenant')->assertNotFound();
    }

    public function test_suspended_tenant_still_resolves_for_read_only_access(): void
    {
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->getJson('http://ubuntu.simuwork.test/api/v1/tenant')->assertOk()->assertJsonPath('data.status', 'suspended');
    }

    public function test_tls_check_allows_only_platform_hosts_and_verified_tenant_domains(): void
    {
        $this->tenant->domains()->create(['host' => 'learn.ubuntuskills.co.za', 'type' => TenantDomain::TYPE_CUSTOM, 'verified_at' => now()]);
        $this->tenant->domains()->create(['host' => 'pending.example.co.za', 'type' => TenantDomain::TYPE_CUSTOM]);

        $check = fn (string $host) => $this->get('/api/v1/internal/domain-allowed?domain='.$host)->getStatusCode();

        $this->assertSame(200, $check('simuwork.test'));
        $this->assertSame(200, $check('admin.simuwork.test'));
        $this->assertSame(200, $check('ubuntu.simuwork.test'));
        $this->assertSame(200, $check('learn.ubuntuskills.co.za'));
        $this->assertSame(404, $check('pending.example.co.za'));
        $this->assertSame(404, $check('evil.example.com'));
        $this->assertSame(404, $check(''));
    }
}
