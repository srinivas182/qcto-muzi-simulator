<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Branding\BrandingService;
use App\Http\Middleware\WebTokenCookie;
use App\Identity\Totp;

/** APIs behind the web app shell: session bootstrap, branding, language, web sign-in cookie. */
final class ShellApiTest extends AccessTestCase
{
    public function test_session_returns_the_menu_for_the_role(): void
    {
        [, $token] = $this->person($this->ubuntu, 'facilitator');

        $data = $this->getJson($this->url('/session'), $this->bearer($token))->assertOk()->json('data');

        $this->assertSame('tenant', $data['area']);
        $ids = array_column($data['navigation'], 'id');
        $this->assertContains('teach.attendance', $ids);
        $this->assertNotContains('admin.billing', $ids);
        $this->assertSame('Ubuntu Skills Academy', $data['tenant']['branding']['display_name']);
        $this->assertNull($data['support']);
        $this->assertArrayHasKey('zu', $data['locales']);
    }

    public function test_hq_session_has_the_hq_menu(): void
    {
        [, $token] = $this->person(null, 'content_manager', ['email' => 'zanele@mayuraconsultancy.com']);

        $data = $this->getJson($this->url('/platform/session', self::HQ), $this->bearer($token))->assertOk()->json('data');

        $this->assertSame('platform', $data['area']);
        $this->assertContains('hq.programmes', array_column($data['navigation'], 'id'));
        $this->assertNotContains('hq.billing', array_column($data['navigation'], 'id'));
        $this->assertNull($data['tenant']);
    }

    public function test_session_shows_the_support_banner_details(): void
    {
        [, $staff] = $this->person(null, 'support_finance', [
            'email' => 'thabo@mayuraconsultancy.com', 'name' => 'Thabo', 'two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now(),
        ]);
        $token = $this->postJson($this->url('/platform/support-sessions', self::HQ), [
            'tenant_id' => $this->ubuntu->id, 'reason' => 'Checking the attendance register export',
        ], $this->bearer($staff))->assertCreated()->json('data.token');

        $data = $this->fresh()->getJson($this->url('/session'), $this->bearer($token))->assertOk()->json('data');

        $this->assertSame('Thabo', $data['support']['staff_name']);
        $this->assertSame('Checking the attendance register export', $data['support']['reason']);
        $this->assertContains('admin.audit', array_column($data['navigation'], 'id'));
        $this->assertNotContains('learn', array_column($data['navigation'], 'id'));
    }

    public function test_admin_updates_branding_and_it_is_public_and_audited(): void
    {
        [, $token] = $this->person($this->ubuntu, 'tp_admin');

        $this->patchJson($this->url('/branding'), ['primary' => '#0f766e', 'display_name' => 'Ubuntu Skills', 'default_locale' => 'zu'], $this->bearer($token))
            ->assertOk()->assertJsonPath('data.primary', '#0F766E');

        $this->fresh()->getJson($this->url('/tenant'))->assertOk()
            ->assertJsonPath('data.branding.display_name', 'Ubuntu Skills')
            ->assertJsonPath('data.branding.default_locale', 'zu')
            ->assertJsonPath('data.branding.accent', BrandingService::DEFAULTS['accent']);
        $this->assertAudited('branding.updated', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_unreadable_colours_are_refused(): void
    {
        [, $token] = $this->person($this->ubuntu, 'tp_admin');

        $this->patchJson($this->url('/branding'), ['primary' => '#FFE066'], $this->bearer($token))
            ->assertStatus(422)->assertJsonValidationErrors('primary');
        $this->patchJson($this->url('/branding'), ['nav' => 'red'], $this->bearer($token))->assertStatus(422);
        $this->patchJson($this->url('/branding'), ['logo_url' => 'http://insecure.example/logo.png'], $this->bearer($token))->assertStatus(422);
    }

    public function test_branding_needs_permission(): void
    {
        [, $token] = $this->person($this->ubuntu, 'facilitator');

        $this->patchJson($this->url('/branding'), ['primary' => '#0F766E'], $this->bearer($token))->assertForbidden();
    }

    public function test_language_preference_is_saved(): void
    {
        [, $token] = $this->person($this->ubuntu, 'learner');

        $this->patchJson($this->url('/auth/preferences'), ['locale' => 'xh'], $this->bearer($token))->assertOk()->assertJsonPath('data.locale', 'xh');
        $this->patchJson($this->url('/auth/preferences'), ['locale' => 'fr'], $this->bearer($token))->assertStatus(422);
    }

    public function test_web_sign_in_uses_an_http_only_cookie(): void
    {
        $this->userIn($this->ubuntu, ['email' => 'nomsa@ubuntuskills.co.za']);

        $response = $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery'], ['X-Client' => 'web'])
            ->assertOk()->assertJsonMissingPath('token')->assertJsonPath('token_type', 'cookie');

        $cookie = $response->getCookie(WebTokenCookie::NAME, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/api', $cookie->getPath());
        $value = (string) $cookie->getValue();

        $this->fresh()->withCredentials()->withUnencryptedCookie(WebTokenCookie::NAME, $value)->getJson($this->url('/auth/me'))->assertOk();

        // Writes with the cookie need the XHR header (CSRF defence).
        $this->fresh()->withCredentials()->withUnencryptedCookie(WebTokenCookie::NAME, $value)->postJson($this->url('/auth/logout'))
            ->assertStatus(419)->assertJsonPath('code', 'CSRF');

        $logout = $this->fresh()->withCredentials()->withUnencryptedCookie(WebTokenCookie::NAME, $value)
            ->postJson($this->url('/auth/logout'), [], ['X-Requested-With' => 'XMLHttpRequest'])->assertOk();
        $this->assertSame('', (string) $logout->getCookie(WebTokenCookie::NAME, false)?->getValue());

        $this->fresh()->withCredentials()->withUnencryptedCookie(WebTokenCookie::NAME, $value)->getJson($this->url('/auth/me'))->assertUnauthorized();
    }

    public function test_mobile_sign_in_still_returns_a_bearer_token(): void
    {
        $this->userIn($this->ubuntu, ['email' => 'nomsa@ubuntuskills.co.za']);

        $this->postJson($this->url('/auth/login'), ['email' => 'nomsa@ubuntuskills.co.za', 'password' => 'correct-horse-battery', 'client' => 'mobile'])
            ->assertOk()->assertJsonPath('token_type', 'Bearer');
    }

    /** Menu ids are translated by the apps as nav.<id>; every id needs an English label there. */
    public function test_every_menu_item_has_a_web_translation(): void
    {
        $file = base_path('../packages/core/src/locales/en.ts');
        if (! is_file($file)) {
            $this->markTestSkipped('Web app not checked out.');
        }
        preg_match_all("/'nav\\.([a-z.]+)':/", (string) file_get_contents($file), $m);

        foreach (['tenant', 'platform'] as $area) {
            foreach ((array) config("navigation.{$area}") as $item) {
                $this->assertContains($item['id'], $m[1], "packages/core/src/locales/en.ts is missing nav.{$item['id']}");
                $this->assertSame('/'.str_replace('.', '/', $item['id']), $item['path'], "id and path of {$item['id']} must match");
            }
        }
    }
}
