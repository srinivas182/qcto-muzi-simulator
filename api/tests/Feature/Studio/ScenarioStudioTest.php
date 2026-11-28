<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Learning\ScenarioContent;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 4.5: Scenario Studio — build, branch, translate, review and publish simulator scenarios. */
final class ScenarioStudioTest extends AccessTestCase
{
    private string $author;

    private string $reviewer;

    private string $publisher;

    private const SCENARIO = [
        'scene' => 'pc-bench', 'pass_mark' => 70, 'job' => ['minutes' => 10, 'customer' => 'Thandi needs her PC.'],
        'variants' => [['id' => 'ram', 'label' => 'Faulty RAM', 'start' => []], ['id' => 'cable', 'label' => 'Loose cable', 'start' => ['psu-cable' => 'loose', 'nope' => 'x']]],
        'steps' => [
            ['id' => 'unplug', 'type' => 'tap', 'target' => 'wall-switch', 'prompt' => 'Make it safe.', 'critical' => true, 'hints' => ['At the wall.'], 'why' => 'Shocks.',
                'wrong' => [['on' => 'case-panel', 'effect' => 'spark', 'message' => 'Live PC!']], 'lesson' => ['code' => 'TS-L01']],
            ['id' => 'fitram', 'type' => 'place', 'target' => 'ram-new', 'slot' => 'slot-a1', 'prompt' => 'Fit the RAM.', 'only' => ['ram', 'ghost']],
            ['id' => 'reseat', 'type' => 'tap', 'target' => 'psu-cable', 'prompt' => 'Reseat the cable.', 'only' => ['cable']],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-test']);
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        [, $this->author] = $this->person(null, 'scenario_designer');
        [, $this->reviewer] = $this->person(null, 'sme_reviewer');
        [, $this->publisher] = $this->person(null, 'content_manager');
    }

    /** @param array<string, mixed> $body */
    private function hq(string $method, string $path, array $body = [], ?string $token = null): TestResponse
    {
        return $this->fresh()->json($method, $this->url('/platform'.$path, self::HQ), $body, $this->bearer($token ?? $this->author));
    }

    private function scenario(): string
    {
        return (string) $this->hq('POST', '/programmes/technical-support/activities', ['kind' => 'scenario', 'title' => 'A PC won’t boot', 'outcomes' => ['TS-2.2']])
            ->assertCreated()->assertJsonPath('data.code', 'TS-S01')->assertJsonPath('data.draft.scenario.scene', 'pc-bench')
            ->assertJsonPath('data.scenes.pc-bench.9', 'psu-cable')->json('data.id');
    }

    public function test_a_branching_scenario_is_cleaned_and_its_problems_listed(): void
    {
        $id = $this->scenario();
        $this->hq('PATCH', "/activities/{$id}", ['scenario' => self::SCENARIO])->assertOk()
            ->assertJsonPath('data.steps', 3)
            ->assertJsonPath('data.draft.scenario.variants.1.start', ['psu-cable' => 'loose']) // unknown objects dropped
            ->assertJsonPath('data.draft.scenario.steps.1.only', ['ram'])                       // unknown causes dropped
            ->assertJsonPath('data.problems', []);

        $broken = self::SCENARIO;
        $broken['steps'][2]['only'] = ['ram'];               // the cable cause now has only the shared step… fine
        $broken['variants'] = [self::SCENARIO['variants'][0]]; // one cause is not branching
        $broken['steps'][0]['target'] = 'teapot';
        $this->hq('PATCH', "/activities/{$id}", ['scenario' => $broken])->assertOk()
            ->assertJsonPath('data.problems', ['Add a second cause, or remove the only one: one cause is the same as none.', 'Step 1: choose the object to touch.']);
    }

    public function test_reviews_reset_when_the_scenario_changes_and_publishing_needs_an_expert_and_real_lessons(): void
    {
        $id = $this->scenario();
        $this->hq('PATCH', "/activities/{$id}", ['scenario' => self::SCENARIO, 'i18n' => ['zu' => ['steps' => ['unplug' => ['prompt' => 'Yenza kuphephe.', 'hints' => ['Odongeni.', 'extra dropped']]], 'job' => ['customer' => 'UThandi udinga i-PC yakhe.']]]])->assertOk()
            ->assertJsonPath('data.draft.i18n.zu.steps.unplug.hints', ['Odongeni.']);
        $this->hq('POST', "/activities/{$id}/review", ['locale' => 'en', 'reviewed' => true], $this->reviewer)->assertOk();
        $this->hq('POST', "/activities/{$id}/review", ['locale' => 'zu', 'reviewed' => true], $this->reviewer)->assertOk()->assertJsonPath('data.review', ['en' => 'reviewed', 'zu' => 'reviewed']);

        // The lesson TS-L01 does not exist yet.
        $this->hq('POST', "/activities/{$id}/publish", [], $this->publisher)->assertUnprocessable()
            ->assertJsonPath('errors.publish.0', 'Step 1: the lesson TS-L01 does not exist in this programme.');
        $this->hq('POST', '/programmes/technical-support/activities', ['kind' => 'lesson', 'title' => 'Safety', 'outcomes' => ['TS-2.2']])->assertCreated();

        // Changing an answer (not the words) needs the English checked again; translations stay reviewed.
        $moved = self::SCENARIO;
        $moved['steps'][0]['target'] = 'pc-power';
        $this->hq('PATCH', "/activities/{$id}", ['scenario' => $moved])->assertJsonPath('data.review', ['zu' => 'reviewed']);
        $this->hq('POST', "/activities/{$id}/publish", [], $this->publisher)->assertUnprocessable();
        $this->hq('PATCH', "/activities/{$id}", ['scenario' => self::SCENARIO])->assertOk();
        $this->hq('POST', "/activities/{$id}/review", ['locale' => 'en', 'reviewed' => true], $this->reviewer)->assertOk();
        $this->hq('POST', "/activities/{$id}/publish", [], $this->publisher)->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.has_draft', false);

        // Changing the English words clears every review.
        $reworded = self::SCENARIO;
        $reworded['steps'][0]['prompt'] = 'Switch it off safely.';
        $this->hq('PATCH', "/activities/{$id}", ['scenario' => $reworded])->assertJsonPath('data.review', [])->assertJsonPath('data.has_draft', true);
    }

    public function test_ai_translates_only_the_scenario_text(): void
    {
        $id = $this->scenario();
        $this->hq('PATCH', "/activities/{$id}", ['scenario' => self::SCENARIO])->assertOk();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode(['steps' => ['unplug' => ['prompt' => 'Maak dit veilig.', 'wrong' => ['Lewendige rekenaar!']]], 'job' => ['customer' => 'Thandi het haar rekenaar nodig.']])]]])]);
        $this->hq('POST', "/activities/{$id}/translate", ['locales' => ['af']])->assertOk()
            ->assertJsonPath('data.draft.i18n.af.steps.unplug.prompt', 'Maak dit veilig.')->assertJsonPath('data.review.af', 'ai');
        Http::assertSent(fn (HttpRequest $r) => str_contains((string) json_encode($r->data()), 'Live PC!') && ! str_contains((string) json_encode($r->data()), 'Faulty RAM'));
    }

    public function test_branching_runs_are_scored_against_the_cause_that_was_played(): void
    {
        [$content] = ScenarioContent::clean(self::SCENARIO);
        $unplug = ['step' => 'unplug', 'action' => 'tap', 'value' => 'wall-switch'];
        $this->assertSame(100, ScenarioContent::score($content, [$unplug, ['step' => 'reseat', 'action' => 'tap', 'value' => 'psu-cable']], 'job', 'cable')['score']);
        $this->assertFalse(ScenarioContent::score($content, [$unplug, ['step' => 'reseat', 'action' => 'tap', 'value' => 'psu-cable']], 'job', 'ram')['completed']);
        $this->assertFalse(ScenarioContent::score($content, [$unplug], 'job', null)['completed']); // the cause must be named

        $learner = ScenarioContent::localise($content + ['review' => ['en' => ['by' => 'HQ']]], 'en');
        $this->assertSame([['id' => 'ram', 'start' => []], ['id' => 'cable', 'start' => ['psu-cable' => 'loose']]], $learner['variants']); // no labels: they give the answer away
    }

    public function test_the_studio_home_lists_scenarios_by_programme(): void
    {
        $this->scenario();
        $this->hq('GET', '/activities?kind=scenario')->assertOk()
            ->assertJsonPath('data.0.title', fn ($t) => is_string($t))
            ->assertJsonFragment(['code' => 'TS-S01', 'kind' => 'scenario']);
        $this->hq('GET', '/activities?kind=nope')->assertUnprocessable();
    }
}
