<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Models\Centre;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Notifications\InvitationLink;
use App\Tenancy\TenantContext;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 3.1: HQ provider management and the provider's own academy details. */
final class ProviderManagementTest extends AccessTestCase
{
    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->super] = $this->person(null, 'super_admin', ['email' => 'srini@hq.test']);
    }

    private function hq(string $path): string
    {
        return $this->url('/platform/providers'.$path, self::HQ);
    }

    public function test_hq_onboards_a_provider_with_its_first_admin(): void
    {
        Notification::fake();

        $res = $this->postJson($this->hq(''), [
            'name' => 'Khanya Skills Institute', 'admin_name' => 'Zanele Khumalo', 'admin_email' => 'zanele@khanya.test',
            'accreditation_number' => 'SDP0712/24-00321', 'accreditation_expires_at' => now()->addYear()->toDateString(), 'vat_number' => '4123456789',
        ], $this->bearer($this->super))->assertCreated()
            ->assertJsonPath('data.slug', 'khanya-skills-institute')->assertJsonPath('data.status', 'trial')
            ->assertJsonPath('data.accreditation.state', 'ok')->assertJsonPath('data.centres', 1);
        $this->assertStringContainsString('khanya-skills-institute.', (string) $res->json('invite_url'));

        $tenant = Tenant::query()->where('slug', 'khanya-skills-institute')->firstOrFail();
        $this->assertSame(1, $tenant->domains()->count());
        $this->assertSame(1, app(TenantContext::class)->runAs($tenant, fn () => Invitation::query()->where('role', 'tp_admin')->count()));
        $this->assertSame(1, app(TenantContext::class)->runAs($tenant, fn () => Centre::query()->count()));
        Notification::assertSentTo(new AnonymousNotifiable, InvitationLink::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'zanele@khanya.test');
        $this->assertAudited('providers.created', ['tenant_id' => $tenant->id]);
    }

    public function test_web_address_must_be_free_and_not_reserved(): void
    {
        Notification::fake();
        $body = ['name' => 'Another Ubuntu', 'admin_name' => 'A Person', 'admin_email' => 'a@b.test'];

        $this->postJson($this->hq(''), $body + ['slug' => 'ubuntu'], $this->bearer($this->super))->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->fresh()->postJson($this->hq(''), $body + ['slug' => 'admin'], $this->bearer($this->super))->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->fresh()->postJson($this->hq(''), $body + ['vat_number' => '123'], $this->bearer($this->super))->assertUnprocessable()->assertJsonValidationErrors('vat_number');
    }

    public function test_viewers_see_providers_but_only_managers_change_them(): void
    {
        [, $support] = $this->person(null, 'support_finance', ['email' => 'thabo@hq.test']);

        $this->getJson($this->hq("/{$this->ubuntu->id}"), $this->bearer($support))->assertOk()->assertJsonPath('data.slug', 'ubuntu');
        $this->fresh()->postJson($this->hq("/{$this->ubuntu->id}/status"), ['status' => 'suspended', 'reason' => 'Unpaid account'], $this->bearer($support))->assertForbidden();
        $this->fresh()->patchJson($this->hq("/{$this->ubuntu->id}"), ['legal_name' => 'Ubuntu (Pty) Ltd'], $this->bearer($support))->assertForbidden();
    }

    public function test_status_changes_follow_the_rules_and_are_audited(): void
    {
        $status = fn (string $s, string $reason = 'Accreditation confirmed') => $this->fresh()->postJson($this->hq("/{$this->lebone->id}/status"), ['status' => $s, 'reason' => $reason], $this->bearer($this->super));

        $status('suspended')->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.status_reason', 'Accreditation confirmed');
        $this->postJson($this->hq("/{$this->lebone->id}/status"), ['status' => 'active'], $this->bearer($this->super))->assertUnprocessable()->assertJsonValidationErrors('reason');
        $status('archived', 'Provider closed its doors')->assertOk();
        $status('active')->assertUnprocessable()->assertJsonValidationErrors('status'); // archiving is final
        $this->assertAudited('providers.status_changed', ['tenant_id' => $this->lebone->id]);
    }

    public function test_a_suspended_providers_staff_are_on_hold_but_learners_are_not(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        [, $learner] = $this->person($this->ubuntu, 'learner');
        $this->ubuntu->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();

        $this->getJson($this->url('/session'), $this->bearer($admin))->assertOk()->assertJsonPath('data.tenant.on_hold', true);
        $this->fresh()->getJson($this->url('/audit'), $this->bearer($admin))->assertStatus(423)->assertJsonPath('code', 'provider_on_hold');
        $this->fresh()->getJson($this->url('/session'), $this->bearer($learner))->assertOk()->assertJsonPath('data.tenant.on_hold', false);
        $this->fresh()->getJson($this->url('/audit'), $this->bearer($learner))->assertForbidden(); // passes the hold, stopped by permissions
    }

    public function test_an_archived_provider_cannot_be_reached(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        $this->ubuntu->forceFill(['status' => Tenant::STATUS_ARCHIVED])->save();

        $this->getJson($this->url('/session'), $this->bearer($admin))->assertNotFound();
    }

    public function test_the_provider_sees_its_details_and_changes_only_contact_and_billing(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        $this->ubuntu->forceFill(['accreditation_number' => 'SDP-1', 'accreditation_expires_at' => now()->addDays(30)])->save();

        $this->getJson($this->url('/academy'), $this->bearer($admin))->assertOk()
            ->assertJsonPath('data.accreditation.state', 'expiring')->assertJsonPath('data.accreditation.days_left', 30);
        $this->fresh()->patchJson($this->url('/academy'), [
            'billing_email' => 'accounts@ubuntu.test', 'address' => ['line1' => '12 Main Rd', 'city' => 'Durban', 'postal_code' => '4001'],
            'accreditation_number' => 'FORGED', 'name' => 'Renamed',
        ], $this->bearer($admin))->assertOk()
            ->assertJsonPath('data.billing_email', 'accounts@ubuntu.test')->assertJsonPath('data.address.city', 'Durban')
            ->assertJsonPath('data.accreditation_number', 'SDP-1')->assertJsonPath('data.name', $this->ubuntu->name);

        [, $facilitator] = $this->person($this->ubuntu, 'facilitator');
        $this->fresh()->getJson($this->url('/academy'), $this->bearer($facilitator))->assertForbidden();
        $this->assertAudited('providers.details_changed', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_hq_can_send_another_admin_invitation(): void
    {
        Notification::fake();
        $this->postJson($this->hq("/{$this->ubuntu->id}/invitations"), ['name' => 'Sipho Mthembu', 'email' => 'sipho@ubuntu.test'], $this->bearer($this->super))
            ->assertCreated()->assertJsonStructure(['invite_url']);
        Notification::assertSentTimes(InvitationLink::class, 1);
    }
}
