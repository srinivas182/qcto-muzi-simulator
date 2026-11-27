<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Identity\Messaging\CodeDelivery;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\DomainService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two providers (ubuntu, lebone) on simuwork.test, with one-time codes captured instead of sent.
 */
abstract class IdentityTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $ubuntu;

    protected Tenant $lebone;

    /** @var array<string, list<string>> phone => codes sent */
    protected array $sentCodes = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.platform_domain' => 'simuwork.test']);
        $sent = &$this->sentCodes;
        $this->app->instance(CodeDelivery::class, new class($sent) implements CodeDelivery
        {
            /** @param array<string, list<string>> $sent */
            public function __construct(private array &$sent) {}

            public function send(string $phoneE164, string $code): string
            {
                $this->sent[$phoneE164][] = $code;

                return 'whatsapp';
            }
        });

        $this->ubuntu = Tenant::factory()->create(['name' => 'Ubuntu Skills Academy', 'slug' => 'ubuntu']);
        $this->lebone = Tenant::factory()->create(['name' => 'Lebone Tech Institute', 'slug' => 'lebone']);
        app(DomainService::class)->createSubdomain($this->ubuntu);
        app(DomainService::class)->createSubdomain($this->lebone);
    }

    protected function url(string $path, string $host = 'ubuntu.simuwork.test'): string
    {
        return 'http://'.$host.'/api/v1'.$path;
    }

    /** @param array<string, mixed> $attributes */
    protected function userIn(?Tenant $tenant, array $attributes = []): User
    {
        $context = app(TenantContext::class);

        $user = $tenant === null
            ? $context->runAs(null, fn () => User::factory()->create($attributes), platform: true)
            : $context->runAs($tenant, fn () => User::factory()->create($attributes));

        $context->clear();

        return $user;
    }

    protected function lastCode(string $phone): string
    {
        $codes = $this->sentCodes[$phone] ?? [];
        $this->assertNotEmpty($codes, "No code was sent to {$phone}");

        return (string) end($codes);
    }

    /** @param array<string, mixed> $user */
    protected function bearer(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    protected function resetAuthGuards(): void
    {
        // Sanctum caches the resolved user on the guard between requests inside one test.
        $this->app['auth']->forgetGuards();
    }
}
