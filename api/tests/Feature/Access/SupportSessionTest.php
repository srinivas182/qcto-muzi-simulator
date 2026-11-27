<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Identity\Totp;

/** HQ read-only support views: reason, time limit, two-factor, visible to the provider, always audited. */
final class SupportSessionTest extends AccessTestCase
{
    /** @return array{0: string, 1: string} staff id, staff token */
    private function supportStaff(bool $twoFactor = true, string $role = 'support_finance'): array
    {
        [$staff, $token] = $this->person(null, $role, array_filter([
            'email' => 'thabo@mayuraconsultancy.com',
            'name' => 'Thabo (HQ Support)',
            'two_factor_secret' => $twoFactor ? Totp::generateSecret() : null,
            'two_factor_confirmed_at' => $twoFactor ? now() : null,
        ], fn ($v) => $v !== null));

        return [$staff->id, $token];
    }

    /** @return array{id: string, token: string} */
    private function open(string $staffToken, string $tenantId, string $reason = 'Provider cannot see learner results on the dashboard'): array
    {
        $data = $this->postJson($this->url('/platform/support-sessions', self::HQ), [
            'tenant_id' => $tenantId, 'reason' => $reason, 'minutes' => 30,
        ], $this->bearer($staffToken))->assertCreated()->json('data');

        $this->fresh();

        return ['id' => $data['session']['id'], 'token' => $data['token']];
    }

    public function test_support_can_view_the_provider_read_only(): void
    {
        [, $staffToken] = $this->supportStaff();
        $this->person($this->ubuntu, 'tp_admin');
        $session = $this->open($staffToken, $this->ubuntu->id);

        $this->getJson($this->url('/roles'), $this->bearer($session['token']))->assertOk();
        $this->getJson($this->url('/auth/me'), $this->bearer($session['token']))->assertOk()->assertJsonPath('data.name', 'Thabo (HQ Support)');

        $this->postJson($this->url('/roles'), ['name' => 'Oops', 'permissions' => ['classes.view']], $this->bearer($session['token']))
            ->assertForbidden()->assertJsonPath('message', 'Support sessions are read-only.');

        $this->assertAudited('support.started', ['tenant_id' => $this->ubuntu->id, 'actor_name' => 'Thabo (HQ Support)']);
    }

    public function test_a_support_token_works_only_for_its_provider(): void
    {
        [, $staffToken] = $this->supportStaff();
        $session = $this->open($staffToken, $this->ubuntu->id);

        $this->getJson($this->url('/roles', 'lebone.simuwork.test'), $this->bearer($session['token']))->assertUnauthorized();
        $this->fresh()->getJson($this->url('/platform/auth/me', self::HQ), $this->bearer($session['token']))->assertUnauthorized();
    }

    public function test_the_provider_sees_the_session_and_can_end_it(): void
    {
        [, $staffToken] = $this->supportStaff();
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        $session = $this->open($staffToken, $this->ubuntu->id);

        $this->getJson($this->url('/support-sessions'), $this->bearer($admin))->assertOk()
            ->assertJsonPath('data.0.staff_name', 'Thabo (HQ Support)')
            ->assertJsonPath('data.0.reason', 'Provider cannot see learner results on the dashboard')
            ->assertJsonPath('data.0.active', true);

        $this->deleteJson($this->url("/support-sessions/{$session['id']}"), [], $this->bearer($admin))->assertOk();

        $this->fresh()->getJson($this->url('/roles'), $this->bearer($session['token']))->assertUnauthorized();
        $this->assertAudited('support.ended', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_sessions_expire(): void
    {
        [, $staffToken] = $this->supportStaff();
        $session = $this->open($staffToken, $this->ubuntu->id);

        $this->travel(31)->minutes();

        $this->getJson($this->url('/roles'), $this->bearer($session['token']))->assertUnauthorized();
    }

    public function test_two_factor_is_required(): void
    {
        [, $staffToken] = $this->supportStaff(twoFactor: false);

        $this->postJson($this->url('/platform/support-sessions', self::HQ), [
            'tenant_id' => $this->ubuntu->id, 'reason' => 'Checking a billing question',
        ], $this->bearer($staffToken))->assertStatus(422)->assertJsonValidationErrors('two_factor');
    }

    public function test_a_reason_is_required(): void
    {
        [, $staffToken] = $this->supportStaff();

        $this->postJson($this->url('/platform/support-sessions', self::HQ), [
            'tenant_id' => $this->ubuntu->id, 'reason' => 'help',
        ], $this->bearer($staffToken))->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    public function test_staff_without_support_access_are_refused(): void
    {
        [, $staffToken] = $this->supportStaff(role: 'content_manager');

        $this->postJson($this->url('/platform/support-sessions', self::HQ), [
            'tenant_id' => $this->ubuntu->id, 'reason' => 'Looking around the provider',
        ], $this->bearer($staffToken))->assertForbidden();
    }

    public function test_session_length_is_capped(): void
    {
        [, $staffToken] = $this->supportStaff();

        $this->postJson($this->url('/platform/support-sessions', self::HQ), [
            'tenant_id' => $this->ubuntu->id, 'reason' => 'Provider cannot see learner results', 'minutes' => 240,
        ], $this->bearer($staffToken))->assertStatus(422)->assertJsonValidationErrors('minutes');
    }
}
