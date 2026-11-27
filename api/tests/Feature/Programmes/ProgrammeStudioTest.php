<?php

declare(strict_types=1);

namespace Tests\Feature\Programmes;

use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Tests\Feature\Access\AccessTestCase;

/** Draft → review → publish, with the right people at each step. */
final class ProgrammeStudioTest extends AccessTestCase
{
    private string $designer;

    private string $reviewer;

    private string $manager;

    protected function setUp(): void
    {
        parent::setUp();
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        [, $this->designer] = $this->person(null, 'scenario_designer', ['email' => 'lwazi@hq.test', 'name' => 'Lwazi']);
        [, $this->reviewer] = $this->person(null, 'sme_reviewer', ['email' => 'priya@hq.test', 'name' => 'Priya']);
        [, $this->manager] = $this->person(null, 'content_manager', ['email' => 'zanele@hq.test', 'name' => 'Zanele']);
    }

    private function hq(string $path): string
    {
        return $this->url('/platform/programmes/technical-support'.$path, self::HQ);
    }

    public function test_full_workflow_from_draft_to_published_version_two(): void
    {
        $as = fn (string $token) => $this->fresh()->withHeaders($this->bearer($token));

        $as($this->designer)->postJson($this->hq('/draft'))->assertCreated()
            ->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.duty_areas.0.outcomes.0.code', 'TS-1.1');

        $as($this->designer)->patchJson($this->hq('/draft/outcomes/TS-1.1'), ['plain_words' => 'Write down every request as a ticket.'])->assertOk()
            ->assertJsonPath('data.duty_areas.0.outcomes.0.plain_words', 'Write down every request as a ticket.');
        $as($this->designer)->postJson($this->hq('/draft/duty-areas/1/outcomes'), ['statement' => 'Use the remote-support tool only with the user’s recorded permission.'])->assertCreated()
            ->assertJsonPath('data.duty_areas.0.outcomes.4.code', 'TS-1.5');
        $as($this->designer)->postJson($this->hq('/draft/duty-areas/1/evidence'), ['description' => 'Screenshot of a permission prompt (no user data)', 'type' => 'photo'])->assertCreated();
        $as($this->designer)->postJson($this->hq('/draft/submit'), ['change_notes' => 'Plain-language lines and remote-support consent'])->assertOk()
            ->assertJsonPath('data.status', 'in_review')->assertJsonPath('data.review.submitted_by', 'Lwazi');

        $as($this->manager)->postJson($this->hq('/draft/publish'))->assertStatus(422)->assertJsonValidationErrors('draft');

        $as($this->reviewer)->postJson($this->hq('/draft/approve'), ['comment' => 'Clear and correct.'])->assertOk()
            ->assertJsonPath('data.review.reviewed_by', 'Priya');
        $as($this->manager)->postJson($this->hq('/draft/publish'))->assertOk()->assertJsonPath('data.status', 'published');

        $changes = $as($this->manager)->getJson($this->hq('/compare?from=1&to=2'))->assertOk()->json('data.changes');
        $this->assertContains(['area' => 'outcomes', 'change' => 'added', 'item' => 'TS-1.5', 'after' => 'Use the remote-support tool only with the user’s recorded permission.'], $changes);
        $this->assertSame('TS-1.1', collect($changes)->firstWhere('change', 'changed')['item']);

        foreach (['programmes.draft_started', 'programmes.submitted', 'programmes.approved', 'programmes.published'] as $action) {
            $this->assertAudited($action);
        }
    }

    public function test_providers_keep_seeing_the_published_version_until_the_new_one_is_published(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        $this->postJson($this->hq('/draft'), [], $this->bearer($this->designer))->assertCreated();
        $this->postJson($this->hq('/draft/duty-areas'), ['title' => 'Field visits'], $this->bearer($this->designer))->assertCreated();

        $this->fresh()->getJson($this->url('/programmes/technical-support'), $this->bearer($admin))->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonCount(6, 'data.duty_areas');
    }

    public function test_each_role_can_only_do_its_own_step(): void
    {
        $this->postJson($this->hq('/draft'), [], $this->bearer($this->reviewer))->assertForbidden();
        $this->fresh()->postJson($this->hq('/draft'), [], $this->bearer($this->designer))->assertCreated();
        $this->fresh()->postJson($this->hq('/draft/submit'), ['change_notes' => 'Small wording fixes only'], $this->bearer($this->designer))->assertOk();
        $this->fresh()->postJson($this->hq('/draft/approve'), [], $this->bearer($this->designer))->assertForbidden();
        $this->fresh()->postJson($this->hq('/draft/publish'), [], $this->bearer($this->reviewer))->assertForbidden();
    }

    public function test_nobody_reviews_their_own_work_except_a_super_admin(): void
    {
        $this->postJson($this->hq('/draft'), [], $this->bearer($this->manager))->assertCreated();
        $this->fresh()->postJson($this->hq('/draft/submit'), ['change_notes' => 'Content manager edit'], $this->bearer($this->manager))->assertOk();
        $this->fresh()->postJson($this->hq('/draft/approve'), [], $this->bearer($this->manager))
            ->assertStatus(422)->assertJsonPath('errors.draft.0', 'Someone other than the author must review this version.');

        [, $srini] = $this->person(null, 'super_admin', ['email' => 'srini@hq.test']);
        $this->fresh()->postJson($this->hq('/draft/request-changes'), ['comment' => 'Please add plain-language lines.'], $this->bearer($srini))->assertOk()
            ->assertJsonPath('data.status', 'draft')->assertJsonPath('data.review.comment', 'Please add plain-language lines.');
    }

    public function test_drafts_in_review_are_locked_until_sent_back(): void
    {
        $this->postJson($this->hq('/draft'), [], $this->bearer($this->designer))->assertCreated();
        $this->fresh()->postJson($this->hq('/draft/submit'), ['change_notes' => 'Ready for a look'], $this->bearer($this->designer))->assertOk();

        $this->fresh()->patchJson($this->hq('/draft'), ['summary' => 'Changing it while in review'], $this->bearer($this->designer))->assertStatus(422);

        $this->fresh()->postJson($this->hq('/draft/request-changes'), ['comment' => 'Summary is too long.'], $this->bearer($this->reviewer))->assertOk();
        $this->fresh()->patchJson($this->hq('/draft'), ['summary' => 'Shorter summary of first-line support.'], $this->bearer($this->designer))->assertOk();
    }

    public function test_outcome_codes_are_never_reused(): void
    {
        $this->postJson($this->hq('/draft'), [], $this->bearer($this->designer))->assertCreated();
        $this->fresh()->deleteJson($this->hq('/draft/outcomes/TS-1.4'), [], $this->bearer($this->designer))->assertOk();

        $this->fresh()->postJson($this->hq('/draft/duty-areas/1/outcomes'), ['statement' => 'A new outcome replacing the old escalation one.'], $this->bearer($this->designer))
            ->assertCreated()->assertJsonPath('data.duty_areas.0.outcomes.3.code', 'TS-1.5');
    }

    public function test_evidence_type_is_validated_and_editable(): void
    {
        $draft = $this->postJson($this->hq('/draft'), [], $this->bearer($this->designer))->assertCreated()->json('data');
        $id = $draft['duty_areas'][0]['evidence'][2]['id'];

        $this->fresh()->patchJson($this->hq("/draft/evidence/{$id}"), ['type' => 'hologram'], $this->bearer($this->designer))->assertStatus(422);
        $this->fresh()->patchJson($this->hq("/draft/evidence/{$id}"), ['type' => 'observation', 'min_count' => 2], $this->bearer($this->designer))->assertOk()
            ->assertJsonPath('data.duty_areas.0.evidence.2.type', 'observation')->assertJsonPath('data.duty_areas.0.evidence.2.min_count', 2);
    }

    public function test_a_draft_can_be_discarded_and_only_one_draft_exists_at_a_time(): void
    {
        $this->postJson($this->hq('/draft'), [], $this->bearer($this->designer))->assertCreated();
        $this->fresh()->postJson($this->hq('/draft'), [], $this->bearer($this->designer))->assertStatus(422);
        $this->fresh()->deleteJson($this->hq('/draft'), [], $this->bearer($this->designer))->assertOk();
        $this->fresh()->postJson($this->hq('/draft'), [], $this->bearer($this->designer))->assertCreated()->assertJsonPath('data.version', 2);
    }
}
