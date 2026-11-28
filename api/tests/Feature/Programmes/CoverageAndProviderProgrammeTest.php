<?php

declare(strict_types=1);

namespace Tests\Feature\Programmes;

use App\Models\ProviderOutcome;
use App\Models\ProviderProgrammeCheck;
use App\Models\Tenant;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 2.3: coverage map (HQ), the provider's "to confirm" checklist and provider additions. */
final class CoverageAndProviderProgrammeTest extends AccessTestCase
{
    private string $hqDesigner;

    private string $ubuntuAdmin;

    private string $leboneAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        [, $this->hqDesigner] = $this->person(null, 'scenario_designer', ['email' => 'lwazi@hq.test', 'name' => 'Lwazi']);
        [, $this->ubuntuAdmin] = $this->person($this->ubuntu, 'tp_admin', ['name' => 'Thandi']);
        [, $this->leboneAdmin] = $this->person($this->lebone, 'tp_admin');
    }

    private function key(int $index = 0): string
    {
        $data = json_decode((string) file_get_contents(database_path(GuideImporter::DEFAULT_FILE)), true);

        return ProviderProgrammeCheck::keyFor($data['programmes'][0]['to_confirm'][$index]);
    }

    private function switchAdditions(Tenant $tenant, bool $on): void
    {
        [, $super] = $this->person(null, 'super_admin', ['email' => uniqid('srini-').'@hq.test']);
        $this->fresh()->patchJson($this->url("/platform/providers/{$tenant->id}/programme-additions", self::HQ), ['enabled' => $on], $this->bearer($super))
            ->assertOk()->assertJsonPath('data.programme_additions', $on);
        $this->fresh();
    }

    // ------------------------------------------------------------------ coverage map

    public function test_hq_records_coverage_and_sees_readiness(): void
    {
        $hq = fn (string $path) => $this->url('/platform/programmes/technical-support'.$path, self::HQ);

        $this->getJson($hq('/coverage'), $this->bearer($this->hqDesigner))->assertOk()
            ->assertJsonPath('data.summary', ['outcomes' => 24, 'ready' => 0, 'planned' => 0, 'readiness' => 0]);

        $this->fresh()->putJson($hq('/coverage/TS-1.1'), ['channel' => 'simulator', 'status' => 'linked', 'title' => 'Help desk triage'], $this->bearer($this->hqDesigner))->assertOk();
        $this->fresh()->putJson($hq('/coverage/TS-1.2'), ['channel' => 'course', 'status' => 'planned'], $this->bearer($this->hqDesigner))->assertOk();
        $map = $this->fresh()->putJson($hq('/coverage/TS-1.2'), ['channel' => 'simulator', 'status' => 'linked'], $this->bearer($this->hqDesigner))->assertOk()
            ->assertJsonPath('data.summary', ['outcomes' => 24, 'ready' => 2, 'planned' => 0, 'readiness' => 8])->json('data.outcomes');
        $this->assertSame('Help desk triage', $map['TS-1.1']['simulator']['title']);
        $this->assertSame('planned', $map['TS-1.2']['course']['status']);

        $this->fresh()->putJson($hq('/coverage/TS-1.2'), ['channel' => 'simulator', 'status' => null], $this->bearer($this->hqDesigner))->assertOk()
            ->assertJsonPath('data.summary.planned', 1)->assertJsonPath('data.summary.ready', 1);

        $overview = $this->fresh()->getJson($this->url('/platform/coverage', self::HQ), $this->bearer($this->hqDesigner))->assertOk()->json('data');
        $this->assertCount(13, $overview);
        $this->assertSame(4, $overview['technical-support']['readiness']);
        $this->assertAudited('programmes.coverage_changed');
    }

    public function test_coverage_rejects_unknown_outcomes_and_needs_an_author(): void
    {
        $this->putJson($this->url('/platform/programmes/technical-support/coverage/TS-99.9', self::HQ), ['channel' => 'course', 'status' => 'planned'], $this->bearer($this->hqDesigner))
            ->assertNotFound();

        [, $reviewer] = $this->person(null, 'sme_reviewer', ['email' => 'priya@hq.test']);
        $this->fresh()->putJson($this->url('/platform/programmes/technical-support/coverage/TS-1.1', self::HQ), ['channel' => 'course', 'status' => 'planned'], $this->bearer($reviewer))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ "to confirm" checklist

    public function test_provider_answers_the_checklist_and_it_stays_with_that_provider(): void
    {
        $url = fn (string $path, string $host = 'ubuntu.simuwork.test') => $this->url('/programmes/technical-support'.$path, $host);

        $this->getJson($url(''), $this->bearer($this->ubuntuAdmin))->assertOk()
            ->assertJsonCount(2, 'provider.checks')->assertJsonPath('provider.checks_done', 0)
            ->assertJsonPath('provider.additions.enabled', false);

        $this->fresh()->putJson($url('/checks/'.$this->key()), ['status' => 'confirmed', 'note' => 'Learners are on the 101408 occupational certificate.'], $this->bearer($this->ubuntuAdmin))
            ->assertOk()->assertJsonPath('provider.checks_done', 1)
            ->assertJsonPath('provider.checks.0.status', 'confirmed')->assertJsonPath('provider.checks.0.answered_by', 'Thandi');
        $this->fresh()->putJson($url('/checks/'.$this->key(1)), ['status' => 'not_applicable'], $this->bearer($this->ubuntuAdmin))
            ->assertUnprocessable()->assertJsonValidationErrors('note');

        // Lebone sees its own (empty) checklist, never Ubuntu's answers.
        $this->fresh()->getJson($url('', 'lebone.simuwork.test'), $this->bearer($this->leboneAdmin))->assertOk()
            ->assertJsonPath('provider.checks_done', 0)->assertJsonPath('provider.checks.0.status', null);
        $this->assertSame(1, app(TenantContext::class)->runAs($this->ubuntu, fn () => ProviderProgrammeCheck::query()->count()));
        $this->assertSame(0, app(TenantContext::class)->runAs($this->lebone, fn () => ProviderProgrammeCheck::query()->count()));
        $this->assertAudited('programmes.check_answered', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_only_people_who_manage_the_catalogue_can_answer(): void
    {
        [, $facilitator] = $this->person($this->ubuntu, 'facilitator');

        $this->putJson($this->url('/programmes/technical-support/checks/'.$this->key()), ['status' => 'confirmed'], $this->bearer($facilitator))->assertForbidden();
        $this->fresh()->putJson($this->url('/programmes/technical-support/checks/'.str_repeat('a', 40)), ['status' => 'confirmed'], $this->bearer($this->ubuntuAdmin))->assertNotFound();
    }

    // ------------------------------------------------------------------ provider additions

    public function test_additions_are_refused_while_hq_has_not_switched_them_on(): void
    {
        $this->postJson($this->url('/programmes/technical-support/additions/outcomes'), ['duty_area' => 1, 'statement' => 'Follow our client’s change-freeze calendar.'], $this->bearer($this->ubuntuAdmin))
            ->assertForbidden();
    }

    public function test_with_the_switch_on_a_provider_adds_its_own_outcomes_and_evidence(): void
    {
        $this->switchAdditions($this->ubuntu, true);
        $base = fn (string $path, string $host = 'ubuntu.simuwork.test') => $this->url('/programmes/technical-support/additions'.$path, $host);

        $this->postJson($base('/outcomes'), ['duty_area' => 1, 'statement' => 'Follow our client’s change-freeze calendar.'], $this->bearer($this->ubuntuAdmin))
            ->assertCreated()->assertJsonPath('provider.additions.outcomes.0.code', 'TS-1.P1');
        $id = $this->fresh()->postJson($base('/outcomes'), ['duty_area' => 1, 'statement' => 'Record each password reset in the client’s log.'], $this->bearer($this->ubuntuAdmin))
            ->assertCreated()->json('provider.additions.outcomes.1.id');
        $this->fresh()->deleteJson($base("/outcomes/{$id}"), [], $this->bearer($this->ubuntuAdmin))->assertOk()->assertJsonCount(1, 'provider.additions.outcomes');
        $this->fresh()->postJson($base('/outcomes'), ['duty_area' => 1, 'statement' => 'Hand over open tickets at the end of each shift.'], $this->bearer($this->ubuntuAdmin))
            ->assertCreated()->assertJsonPath('provider.additions.outcomes.1.code', 'TS-1.P3'); // P2 is never reused
        $this->fresh()->postJson($base('/evidence'), ['duty_area' => 2, 'description' => 'Signed shift handover sheet', 'type' => 'sign_off'], $this->bearer($this->ubuntuAdmin))
            ->assertCreated()->assertJsonPath('provider.additions.evidence.0.type', 'sign_off');
        $this->fresh()->postJson($base('/outcomes'), ['duty_area' => 42, 'statement' => 'An outcome for a duty area that does not exist.'], $this->bearer($this->ubuntuAdmin))
            ->assertUnprocessable()->assertJsonValidationErrors('duty_area');

        // HQ's published version is untouched, and Lebone sees none of it.
        $this->fresh()->getJson($this->url('/programmes/technical-support'), $this->bearer($this->ubuntuAdmin))->assertOk()
            ->assertJsonCount(4, 'data.duty_areas.0.outcomes')->assertJsonCount(2, 'provider.additions.outcomes');
        $this->fresh()->getJson($this->url('/programmes/technical-support', 'lebone.simuwork.test'), $this->bearer($this->leboneAdmin))->assertOk()
            ->assertJsonCount(0, 'provider.additions.outcomes')->assertJsonCount(0, 'provider.additions.evidence');
        $this->fresh()->patchJson($base("/outcomes/{$id}", 'lebone.simuwork.test'), ['statement' => 'Trying to change another provider’s outcome.'], $this->bearer($this->leboneAdmin))
            ->assertForbidden(); // switch is off for Lebone; even when on, the row is invisible (see below)
        $this->assertAudited('programmes.addition_added', ['tenant_id' => $this->ubuntu->id]);
        $this->assertAudited('providers.programme_additions_changed', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_another_provider_cannot_reach_additions_even_with_its_own_switch_on(): void
    {
        $this->switchAdditions($this->ubuntu, true);
        $this->switchAdditions($this->lebone, true);
        $id = $this->postJson($this->url('/programmes/technical-support/additions/outcomes'), ['duty_area' => 1, 'statement' => 'Follow our client’s change-freeze calendar.'], $this->bearer($this->ubuntuAdmin))
            ->assertCreated()->json('provider.additions.outcomes.0.id');

        $this->fresh()->deleteJson($this->url("/programmes/technical-support/additions/outcomes/{$id}", 'lebone.simuwork.test'), [], $this->bearer($this->leboneAdmin))->assertNotFound();
        $this->assertSame(1, app(TenantContext::class)->runAs($this->ubuntu, fn () => ProviderOutcome::query()->count()));
    }

    public function test_switching_off_keeps_existing_additions_read_only(): void
    {
        $this->switchAdditions($this->ubuntu, true);
        $this->postJson($this->url('/programmes/technical-support/additions/outcomes'), ['duty_area' => 1, 'statement' => 'Follow our client’s change-freeze calendar.'], $this->bearer($this->ubuntuAdmin))->assertCreated();
        $this->switchAdditions($this->ubuntu, false);

        $this->getJson($this->url('/programmes/technical-support'), $this->bearer($this->ubuntuAdmin))->assertOk()
            ->assertJsonPath('provider.additions.enabled', false)->assertJsonCount(1, 'provider.additions.outcomes');
        $this->fresh()->postJson($this->url('/programmes/technical-support/additions/outcomes'), ['duty_area' => 1, 'statement' => 'Another outcome after switch-off.'], $this->bearer($this->ubuntuAdmin))
            ->assertForbidden();
    }

    public function test_hq_lists_providers_and_only_provider_managers_flip_the_switch(): void
    {
        [, $support] = $this->person(null, 'support_finance', ['email' => 'thabo@hq.test']);

        $providers = $this->getJson($this->url('/platform/providers', self::HQ), $this->bearer($support))->assertOk()->json('data');
        $ubuntu = collect($providers)->firstWhere('id', $this->ubuntu->id);
        $this->assertSame(['slug' => 'ubuntu', 'programme_additions' => false], ['slug' => $ubuntu['slug'], 'programme_additions' => $ubuntu['programme_additions']]);

        $this->fresh()->patchJson($this->url("/platform/providers/{$this->ubuntu->id}/programme-additions", self::HQ), ['enabled' => true], $this->bearer($support))->assertForbidden();
        $this->fresh()->getJson($this->url('/platform/providers', self::HQ), $this->bearer($this->ubuntuAdmin))->assertUnauthorized(); // provider tokens never work on HQ
    }
}
