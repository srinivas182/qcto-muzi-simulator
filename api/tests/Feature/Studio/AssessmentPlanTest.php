<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Assessment\QuestionBank;
use App\Learning\ActivityCatalogue;
use App\Models\ProgrammeVersion;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 5.1a: the assessment plan, outcome coverage and question banks. */
final class AssessmentPlanTest extends AccessTestCase
{
    private string $author;

    private string $reviewer;

    private string $publisher;

    private const QUESTIONS = [
        ['type' => 'single', 'prompt' => 'What do you do first?', 'options' => ['Open the case', 'Switch off and unplug'], 'answer' => 1, 'explain' => 'Power first.', 'outcome' => 'TS-2.2'],
        ['type' => 'multi', 'prompt' => 'Which protect parts from static?', 'options' => ['Wrist strap', 'Carpet', 'Antistatic mat'], 'answer' => [2, 0, 9], 'outcome' => 'TS-2.2'],
        ['type' => 'short', 'prompt' => 'Explain why you test before closing the ticket.', 'model_answer' => 'To confirm the fault is fixed for the user.', 'marks' => 3, 'outcome' => 'TS-2.2'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.key' => 'test-key']);
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

    private function test(): string
    {
        return (string) $this->hq('POST', '/programmes/technical-support/assessment-tasks', ['title' => 'Knowledge test: PC repair', 'component' => 'knowledge', 'kind' => 'test', 'outcomes' => ['TS-2.2']])
            ->assertCreated()->assertJsonPath('data.code', 'TS-A01')->assertJsonPath('data.settings.integrity', 'light')->json('data.id');
    }

    public function test_the_plan_shows_which_outcomes_each_component_covers(): void
    {
        $total = DB::table('outcomes')->join('programme_versions', 'programme_versions.id', '=', 'outcomes.programme_version_id')
            ->join('programmes', 'programmes.id', '=', 'programme_versions.programme_id')->where('programmes.code', 'technical-support')->where('programme_versions.status', 'published')->count();
        $this->hq('GET', '/programmes/technical-support/assessment')->assertOk()->assertJsonPath('data.uncovered', $total);
        $this->test();
        $this->hq('POST', '/programmes/technical-support/assessment-tasks', ['title' => 'Logbook', 'component' => 'workplace', 'kind' => 'logbook', 'outcomes' => ['TS-2.2', 'TS-3.2']])->assertCreated();
        $this->hq('POST', '/programmes/technical-support/assessment-tasks', ['title' => 'Wrong', 'component' => 'knowledge', 'kind' => 'logbook', 'outcomes' => ['TS-2.2']])->assertUnprocessable();
        $this->hq('POST', '/programmes/technical-support/assessment-tasks', ['title' => 'Sim', 'component' => 'practical', 'kind' => 'simulator', 'outcomes' => ['TS-3.2']])->assertUnprocessable()->assertJsonValidationErrors('activity_id');

        $plan = $this->hq('GET', '/programmes/technical-support/assessment')->assertOk()->assertJsonPath('data.uncovered', $total - 2);
        $row = collect($plan->json('data.coverage'))->firstWhere('code', 'TS-2.2');
        $this->assertSame(['knowledge', 'workplace'], $row['components']);
    }

    public function test_a_question_bank_is_cleaned_reviewed_and_published_like_content(): void
    {
        $id = $this->test();
        $saved = $this->hq('PATCH', "/assessment-tasks/{$id}", ['questions' => self::QUESTIONS, 'settings' => ['pick' => 5, 'minutes' => 20, 'integrity' => 'camera']])->assertOk()
            ->assertJsonPath('data.draft.questions.1.answer', [0, 2])->assertJsonPath('data.draft.settings.integrity', 'camera');
        $this->assertContains('The bank has 3 questions; each test draws 5. Add questions or draw fewer.', $saved->json('data.problems'));

        $this->hq('PATCH', "/assessment-tasks/{$id}", ['settings' => ['pick' => 3, 'minutes' => 20, 'integrity' => 'camera']])->assertJsonPath('data.problems', []);
        $this->hq('POST', "/assessment-tasks/{$id}/publish", [], $this->publisher)->assertUnprocessable(); // no expert review yet
        $this->hq('POST', "/assessment-tasks/{$id}/review", ['locale' => 'en', 'reviewed' => true], $this->reviewer)->assertOk()->assertJsonPath('data.review.en', 'reviewed');
        $this->hq('POST', "/assessment-tasks/{$id}/publish", [], $this->publisher)->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.has_draft', false);
        $this->assertAudited('assessment_tasks.published');

        // Changing the English clears every review.
        $edited = self::QUESTIONS;
        $edited[0]['prompt'] = 'What must you do first?';
        $this->hq('PATCH', "/assessment-tasks/{$id}", ['questions' => $edited])->assertJsonPath('data.review', []);
        $this->hq('POST', "/assessment-tasks/{$id}/review", ['locale' => 'en', 'reviewed' => true], $this->author)->assertForbidden(); // authors cannot review
    }

    public function test_ai_drafts_questions_and_translations_from_programme_content_only(): void
    {
        $id = $this->test();
        Http::fake(function (HttpRequest $r) {
            $prompt = (string) ($r['messages'][0]['content'] ?? '');
            if (! str_contains($prompt, 'Translate')) {
                return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['questions' => self::QUESTIONS])]]]);
            }
            $source = json_decode(substr($prompt, (int) strpos($prompt, '{')), true)['questions'];
            $first = array_key_first($source);

            return Http::response(['content' => [['type' => 'text', 'text' => json_encode(['questions' => [$first => ['prompt' => 'Wenzani kuqala?', 'options' => ['Vula', 'Cisha']]]])]]]);
        });
        $draft = $this->hq('POST', "/assessment-tasks/{$id}/ai-draft", ['count' => 3])->assertOk()->assertJsonPath('data.questions', 3)->assertJsonPath('data.review.en', 'ai');
        Http::assertSent(fn (HttpRequest $r) => str_contains((string) json_encode($r->data()), 'TS-2.2') && ! str_contains((string) json_encode($r->data()), '@'));

        $first = (string) $draft->json('data.draft.questions.0.id');
        $this->hq('POST', "/assessment-tasks/{$id}/translate", ['locales' => ['zu']])->assertOk()
            ->assertJsonPath("data.draft.i18n.zu.{$first}.prompt", 'Wenzani kuqala?')->assertJsonPath('data.review.zu', 'ai');
    }

    public function test_model_answers_are_never_translated_and_short_questions_need_one(): void
    {
        [$q, $problems] = QuestionBank::clean([['type' => 'short', 'prompt' => 'Why?', 'outcome' => 'X']], ['X']);
        $this->assertSame(['Question 1: write the model answer the assessor marks against.'], $problems);
        $i18n = QuestionBank::cleanI18n(['zu' => [$q[0]['id'] => ['prompt' => 'Kungani?', 'model_answer' => 'no']]], $q);
        $this->assertSame(['prompt' => 'Kungani?'], $i18n['zu'][$q[0]['id']]);
    }

    public function test_a_simulator_task_points_at_a_published_scenario(): void
    {
        $version = ProgrammeVersion::query()->whereHas('programme', fn ($q) => $q->where('code', 'technical-support'))->where('status', 'published')->firstOrFail();
        $sim = app(TenantContext::class)->runAs(null, fn () => app(ActivityCatalogue::class)->create($version, ['kind' => 'scenario', 'code' => 'TS-S01', 'title' => 'A PC won’t boot'], ['TS-3.2']), platform: true);
        $id = (string) $this->hq('POST', '/programmes/technical-support/assessment-tasks', ['title' => 'Practical: replace RAM', 'component' => 'practical', 'kind' => 'simulator', 'activity_id' => $sim->id, 'outcomes' => ['TS-3.2']])
            ->assertCreated()->assertJsonPath('data.settings.mode', 'job')->json('data.id');
        $this->hq('POST', "/assessment-tasks/{$id}/publish", [], $this->publisher)->assertUnprocessable()->assertJsonPath('errors.publish.0', 'Publish the simulator first.');
    }
}
