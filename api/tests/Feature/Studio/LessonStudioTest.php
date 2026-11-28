<?php

declare(strict_types=1);

namespace Tests\Feature\Studio;

use App\Learning\LessonContent;
use App\Learning\LessonStudio;
use App\Models\Activity;
use App\Models\ProgrammeVersion;
use App\Programmes\GuideImporter;
use App\Programmes\ProgrammeVersions;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 4.2: Course Studio — lesson drafts, AI drafting and translation, review, publishing, assets. */
final class LessonStudioTest extends AccessTestCase
{
    private string $author;

    private string $reviewer;

    private string $publisher;

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

    private function lesson(): string
    {
        return (string) $this->hq('POST', '/programmes/technical-support/activities', ['kind' => 'lesson', 'title' => 'Working safely inside a PC', 'summary' => 'Antistatic strap and power off.', 'outcomes' => ['TS-2.2']])
            ->assertCreated()->assertJsonPath('data.code', 'TS-L01')->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    /** @param array<string, mixed> $json */
    private static function aiAnswer(array $json): array
    {
        return ['content' => [['type' => 'text', 'text' => 'Here you go: '.json_encode($json)]]];
    }

    private const BLOCKS = [
        ['type' => 'narration', 'script' => 'Hi! Today you learn to work safely inside a PC.'],
        ['type' => 'keypoints', 'items' => ['Switch off and unplug', 'Wear an antistatic strap']],
        ['type' => 'check', 'prompt' => 'What do you do first?', 'options' => ['Open the case', 'Switch off and unplug'], 'answer' => 1, 'explain' => 'Power can hurt you and the PC.', 'outcome' => 'TS-2.2'],
    ];

    public function test_ai_drafts_a_lesson_from_its_outcomes_and_only_programme_content_is_sent(): void
    {
        $id = $this->lesson();
        Http::fake(['api.anthropic.com/*' => Http::response(self::aiAnswer(['blocks' => [...self::BLOCKS, ['type' => 'video', 'asset_id' => 'x', 'text' => 'not allowed from AI']]]))]);
        $this->hq('POST', "/activities/{$id}/ai-draft")->assertOk()->assertJsonCount(3, 'data.draft.blocks')
            ->assertJsonPath('data.draft.blocks.2.answer', 1)->assertJsonPath('data.review.en', 'ai')->assertJsonPath('data.problems', []);
        Http::assertSent(function (HttpRequest $r): bool {
            $body = (string) json_encode($r->data());

            return $r->hasHeader('x-api-key', 'test-key') && str_contains($body, 'TS-2.2') && ! str_contains($body, '@') && $r['model'] === 'claude-test';
        });
        $this->assertAudited('activities.ai_drafted');

        config(['services.anthropic.key' => '']);
        $this->hq('POST', "/activities/{$id}/ai-draft")->assertUnprocessable()->assertJsonPath('errors.ai.0', 'AI drafting is not set up: add ANTHROPIC_API_KEY.');
    }

    public function test_translations_are_drafted_reviewed_and_only_reviewed_ones_reach_learners(): void
    {
        $id = $this->lesson();
        $this->hq('PATCH', "/activities/{$id}", ['blocks' => self::BLOCKS])->assertOk();
        $blocks = $this->hq('GET', "/activities/{$id}")->json('data.draft.blocks');
        $zu = [];
        foreach ($blocks as $b) {
            $zu[$b['id']] = match ($b['type']) {
                'narration' => ['script' => 'Sawubona! Namuhla ufunda ukusebenza ngokuphepha ngaphakathi kwe-PC.', 'answer' => 0],
                'keypoints' => ['items' => ['Cisha bese ukhipha ugesi', 'Gqoka i-antistatic strap']],
                default => ['prompt' => 'Wenzani kuqala?', 'options' => ['Vula ikesi', 'Cisha bese ukhipha ugesi'], 'explain' => 'Ugesi ungakulimaza.'],
            };
        }
        Http::fake(['api.anthropic.com/*' => Http::response(self::aiAnswer(['blocks' => $zu]))]);
        $res = $this->hq('POST', "/activities/{$id}/translate", ['locales' => ['zu']])->assertOk()->assertJsonPath('data.review.zu', 'ai');
        $this->assertArrayNotHasKey('answer', $res->json("data.draft.i18n.zu.{$blocks[0]['id']}")); // only text fields can be translated

        $this->hq('POST', "/activities/{$id}/publish", [], $this->publisher)->assertUnprocessable()
            ->assertJsonPath('errors.publish.0', 'A subject expert must review the English lesson before it is published.');
        $this->hq('POST', "/activities/{$id}/review", ['locale' => 'en', 'reviewed' => true])->assertForbidden(); // authors cannot review
        $this->hq('POST', "/activities/{$id}/review", ['locale' => 'en', 'reviewed' => true], $this->reviewer)->assertOk()->assertJsonPath('data.review.en', 'reviewed');
        $this->hq('POST', "/activities/{$id}/publish", [], $this->reviewer)->assertForbidden(); // reviewers cannot publish
        $this->hq('POST', "/activities/{$id}/publish", [], $this->publisher)->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.content_version', 1);

        $a = Activity::query()->findOrFail($id);
        $this->assertArrayHasKey('zu', $a->content['i18n']); // kept for the reviewer ...
        $this->assertSame('Hi! Today you learn to work safely inside a PC.', LessonContent::localise($a->content, 'zu')[0]['script']); // ... but learners get English until it is reviewed
        $this->assertSame('linked', DB::table('outcome_coverage')->where('outcome_code', 'TS-2.2')->where('channel', 'course')->value('status'));

        // Review isiZulu and publish again: learners now get it, and the content version goes up.
        $this->hq('POST', "/activities/{$id}/review", ['locale' => 'zu', 'reviewed' => true], $this->reviewer)->assertOk();
        $this->hq('POST', "/activities/{$id}/publish", [], $this->publisher)->assertOk()->assertJsonPath('data.content_version', 2);
        $this->assertStringStartsWith('Sawubona', LessonContent::localise(Activity::query()->findOrFail($id)->content, 'zu')[0]['script']);
    }

    public function test_changing_the_english_text_clears_every_review(): void
    {
        $id = $this->lesson();
        $this->hq('PATCH', "/activities/{$id}", ['blocks' => self::BLOCKS]);
        $this->hq('POST', "/activities/{$id}/review", ['locale' => 'en', 'reviewed' => true], $this->reviewer)->assertJsonPath('data.review.en', 'reviewed');
        $blocks = $this->hq('GET', "/activities/{$id}")->json('data.draft.blocks');
        $blocks[0]['script'] = 'Changed';
        $this->hq('PATCH', "/activities/{$id}", ['blocks' => $blocks])->assertOk()->assertJsonPath('data.review', []);
    }

    public function test_publishing_checks_the_lesson_is_complete(): void
    {
        $id = $this->lesson();
        $this->hq('PATCH', "/activities/{$id}", ['blocks' => [['type' => 'check', 'prompt' => 'Q?', 'options' => ['A'], 'answer' => 3], ['type' => 'try', 'activity_code' => 'TS-S99']]])
            ->assertOk()->assertJsonCount(1, 'data.problems');
        $this->hq('POST', "/activities/{$id}/review", ['locale' => 'en', 'reviewed' => true], $this->reviewer);
        $errors = $this->hq('POST', "/activities/{$id}/publish", [], $this->publisher)->assertUnprocessable()->json('errors.publish');
        $this->assertSame(['Block 1: a check needs a question, at least two answers, the right one marked, and why it is right.', 'The simulator TS-S99 does not exist in this programme.'], $errors);
    }

    public function test_uploads_are_checked_by_real_type_and_size_and_stored_once(): void
    {
        Storage::fake('public');
        $png = UploadedFile::fake()->image('strap.png', 400, 300);
        $first = $this->hq('POST', '/content-assets', ['kind' => 'image', 'file' => $png])->assertCreated()->json('data.id');
        $this->assertSame($first, $this->hq('POST', '/content-assets', ['kind' => 'image', 'file' => $png])->json('data.id'));
        $this->hq('POST', '/content-assets', ['kind' => 'image', 'file' => UploadedFile::fake()->create('virus.png', 10, 'application/x-msdownload')])->assertUnprocessable();
        $this->hq('POST', '/content-assets', ['kind' => 'image', 'file' => UploadedFile::fake()->image('huge.jpg')->size(900)])->assertUnprocessable()
            ->assertJsonPath('errors.file.0', "The file is too big for learners' phones. Keep it under 400 KB.");
    }

    public function test_lessons_carry_forward_when_a_new_programme_version_is_published(): void
    {
        $id = $this->lesson();
        $old = ProgrammeVersion::query()->whereHas('programme', fn ($q) => $q->where('code', 'technical-support'))->where('status', 'published')->firstOrFail();
        $new = $old->replicate();
        $new->number = $old->number + 1;
        $new->status = 'in_review';
        $new->save();
        foreach (DB::table('outcomes')->where('programme_version_id', $old->id)->where('code', '<>', 'TS-2.2')->get() as $o) {
            DB::table('outcomes')->insert(['id' => (string) Str::uuid(), 'programme_version_id' => $new->id, 'duty_area_id' => $o->duty_area_id, 'code' => $o->code, 'position' => $o->position, 'statement' => $o->statement, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(TenantContext::class)->runAs(null, fn () => app(ProgrammeVersions::class)->publish($new), platform: true);

        $copy = Activity::query()->where('programme_version_id', $new->id)->where('code', 'TS-L01')->firstOrFail();
        $this->assertSame([], $copy->outcomeCodes()); // TS-2.2 was removed in the new version
        $this->assertTrue(Activity::query()->whereKey($id)->exists()); // learners on the old version keep theirs
        $this->assertSame(1, LessonStudio::carryForward($old, $new) + 1); // running again copies nothing more
    }

    public function test_providers_cannot_reach_the_studio(): void
    {
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        $this->fresh()->getJson($this->url('/platform/programmes/technical-support/activities'), $this->bearer($admin))->assertStatus(404);
    }

    public function test_a_check_can_carry_a_fix_it_hint_that_is_translatable(): void
    {
        [$blocks, $problems] = LessonContent::clean([['type' => 'check', 'prompt' => 'First?', 'options' => ['Open it', 'Unplug it'], 'answer' => 1, 'explain' => 'Power first.', 'hint' => str_repeat('x', 300)]]);
        $this->assertSame([], $problems);
        $this->assertSame(200, mb_strlen($blocks[0]['hint']));
        $this->assertContains('hint', LessonContent::TEXT_FIELDS['check']);
    }
}
