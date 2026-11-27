<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_ok_when_dependencies_respond(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'app' => config('app.name'),
                'checks' => ['database' => 'ok', 'cache' => 'ok'],
            ])
            ->assertJsonStructure(['status', 'app', 'version', 'checks']);
    }

    public function test_health_endpoint_reports_degraded_when_cache_fails(): void
    {
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('down'));

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.cache', 'fail');
    }

    public function test_root_returns_json_pointer_to_api(): void
    {
        $this->getJson('/')->assertOk()->assertJsonStructure(['app', 'api']);
    }
}
