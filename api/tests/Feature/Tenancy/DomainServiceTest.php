<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Tenancy\Dns\DnsResolver;
use App\Tenancy\DomainService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DomainServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private array $dnsRecords = [];

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.platform_domain' => 'simuwork.test']);
        $records = &$this->dnsRecords;
        $this->app->instance(DnsResolver::class, new class($records) implements DnsResolver
        {
            /** @param array<string, list<string>> $records */
            public function __construct(private array &$records) {}

            public function txt(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        });

        $this->tenant = Tenant::factory()->create(['slug' => 'ubuntu']);
    }

    private function service(): DomainService
    {
        return app(DomainService::class);
    }

    public function test_subdomain_is_created_verified_and_primary(): void
    {
        $domain = $this->service()->createSubdomain($this->tenant);

        $this->assertSame('ubuntu.simuwork.test', $domain->host);
        $this->assertTrue($domain->is_primary);
        $this->assertTrue($domain->isVerified());
    }

    public function test_custom_domain_starts_unverified_with_a_dns_record_to_publish(): void
    {
        $domain = $this->service()->addCustomDomain($this->tenant, ' Learn.UbuntuSkills.co.za. ');

        $this->assertSame('learn.ubuntuskills.co.za', $domain->host);
        $this->assertFalse($domain->isVerified());

        $record = $this->service()->verificationRecord($domain);
        $this->assertSame('TXT', $record['type']);
        $this->assertSame('_simuwork-verify.learn.ubuntuskills.co.za', $record['name']);
        $this->assertStringStartsWith('simuwork-', $record['value']);
    }

    public function test_domain_is_verified_when_the_txt_record_matches(): void
    {
        $domain = $this->service()->addCustomDomain($this->tenant, 'learn.ubuntuskills.co.za');

        $this->assertFalse($this->service()->verify($domain));
        $this->assertNotNull($domain->fresh()?->last_checked_at);

        $this->dnsRecords['_simuwork-verify.learn.ubuntuskills.co.za'] = ['some-other-value', (string) $domain->verification_token];

        $this->assertTrue($this->service()->verify($domain));
        $this->assertTrue($domain->fresh()?->isVerified());
    }

    public function test_verified_domain_resolves_immediately_after_verification(): void
    {
        $domain = $this->service()->addCustomDomain($this->tenant, 'learn.ubuntuskills.co.za');
        $this->getJson('http://learn.ubuntuskills.co.za/api/v1/tenant')->assertNotFound();

        $this->dnsRecords['_simuwork-verify.learn.ubuntuskills.co.za'] = [(string) $domain->verification_token];
        $this->service()->verify($domain);

        $this->getJson('http://learn.ubuntuskills.co.za/api/v1/tenant')->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function invalidHosts(): array
    {
        return [
            'not a domain' => ['not a domain'],
            'no tld' => ['localhost'],
            'platform sub-domain' => ['ubuntu.simuwork.test'],
            'platform domain' => ['simuwork.test'],
        ];
    }

    /** @dataProvider invalidHosts */
    public function test_invalid_or_platform_hosts_are_rejected(string $host): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->addCustomDomain($this->tenant, $host);
    }

    public function test_a_domain_cannot_be_connected_twice(): void
    {
        $other = Tenant::factory()->create();
        $this->service()->addCustomDomain($this->tenant, 'learn.ubuntuskills.co.za');

        $this->expectException(ValidationException::class);

        $this->service()->addCustomDomain($other, 'learn.ubuntuskills.co.za');
    }

    public function test_tenant_create_command_creates_tenant_and_subdomain(): void
    {
        $this->artisan('tenant:create', ['name' => 'Lebone Tech Institute'])
            ->expectsOutputToContain('lebone-tech-institute.simuwork.test')
            ->assertSuccessful();

        $tenant = Tenant::query()->where('slug', 'lebone-tech-institute')->firstOrFail();
        $this->assertSame(1, $tenant->domains()->where('type', TenantDomain::TYPE_SUBDOMAIN)->count());
    }

    public function test_tenant_create_command_refuses_reserved_slugs(): void
    {
        $this->artisan('tenant:create', ['name' => 'Admin', '--slug' => 'admin'])->assertFailed();
    }
}
