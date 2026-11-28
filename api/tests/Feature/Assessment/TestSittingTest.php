<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Assessment\QuestionBank;
use App\Learners\IdNumbers;
use App\Models\AssessmentTask;
use App\Models\Centre;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\Package;
use App\Models\ProgrammeVersion;
use App\Models\TestSitting;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 5.1b: learners sit knowledge tests; the server keeps the answers, the clock and the marking. */
final class TestSittingTest extends AccessTestCase
{
    private ProgrammeVersion $version;

    private AssessmentTask $task;

    private string $token;

    private Enrolment $enrolment;

    private const BANK = [
        ['id' => 'qsingle1', 'type' => 'single', 'prompt' => 'What do you do first?', 'options' => ['Open the case', 'Switch off and unplug', 'Call the user'], 'answer' => 1, 'explain' => 'Power first.', 'outcome' => 'TS-2.2', 'marks' => 1],
        ['id' => 'qmulti01', 'type' => 'multi', 'prompt' => 'Which protect parts from static?', 'options' => ['Wrist strap', 'Carpet', 'Antistatic mat'], 'answer' => [0, 2], 'explain' => 'Earth yourself.', 'outcome' => 'TS-2.2', 'marks' => 2],
        ['id' => 'qshort01', 'type' => 'short', 'prompt' => 'Why test before closing the ticket?', 'model_answer' => 'To confirm the fix works for the user.', 'outcome' => 'TS-3.2', 'marks' => 3],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 09:00:00');
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        $programme = (string) DB::table('programmes')->where('code', 'technical-support')->value('id');
        $this->version = ProgrammeVersion::query()->where('programme_id', $programme)->where('status', 'published')->firstOrFail();
        [$questions] = QuestionBank::clean(self::BANK, ['TS-2.2', 'TS-3.2']);
        $this->task = AssessmentTask::query()->create([
            'programme_version_id' => $this->version->id, 'code' => 'TS-A01', 'title' => 'Knowledge test', 'component' => 'knowledge', 'kind' => 'test', 'position' => 1,
            'settings' => ['pick' => 3, 'minutes' => 20, 'pass_mark' => 70, 'attempts' => 2, 'integrity' => 'light'], 'status' => 'published',
            'content' => ['questions' => $questions, 'i18n' => ['zu' => ['qsingle1' => ['prompt' => 'Wenzani kuqala?']]], 'review' => ['en' => ['by' => 'HQ'], 'zu' => ['by' => 'HQ']]],
        ]);
        DB::table('assessment_task_outcomes')->insert([['assessment_task_id' => $this->task->id, 'outcome_code' => 'TS-2.2'], ['assessment_task_id' => $this->task->id, 'outcome_code' => 'TS-3.2']]);
        [$this->token, $this->enrolment] = $this->enrolled();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: string, 1: Enrolment} */
    private function enrolled(): array
    {
        [$user, $token] = $this->person($this->ubuntu, 'learner');
        $package = Package::query()->create(['code' => 'ts'.Str::random(4), 'name' => 'TS', 'programme_id' => $this->version->programme_id, 'includes_simulators' => true, 'includes_courses' => true, 'billing' => 'term', 'term_months' => 12, 'min_seats' => 1]);

        return [$token, app(TenantContext::class)->runAs($this->ubuntu, function () use ($user, $package): Enrolment {
            $pool = LicencePool::query()->forceCreate(['package_id' => $package->id, 'programme_id' => $this->version->programme_id, 'billing' => 'term', 'term_months' => 12, 'unit_price_cents' => 1, 'seats' => 5, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'status' => 'active']);
            $cohort = Cohort::query()->create(['centre_id' => Centre::query()->create(['name' => 'Main'])->id, 'programme_id' => $this->version->programme_id, 'programme_version_id' => $this->version->id, 'name' => 'TS', 'starts_on' => '2027-02-01', 'join_code' => strtoupper(Str::random(8))]);
            $learner = Learner::query()->create(['user_id' => $user->id, 'first_names' => 'Sipho', 'surname' => 'Ndlovu', 'id_type' => 'passport', 'phone' => '+2782'.random_int(1000000, 9999999), 'status' => 'active'] + IdNumbers::protect('passport', 'P'.random_int(1000000, 9999999)));

            return Enrolment::query()->create(['learner_id' => $learner->id, 'cohort_id' => $cohort->id, 'programme_id' => $this->version->programme_id, 'programme_version_id' => $this->version->id, 'licence_pool_id' => $pool->id, 'seat_taken_at' => '2027-02-01 08:00:00', 'status' => 'active']);
        })];
    }

    private function start(?string $token = null): TestResponse
    {
        return $this->fresh()->postJson($this->url("/learn/enrolments/{$this->enrolment->id}/tests/{$this->task->id}/start"), ['locale' => 'zu'], $this->bearer($token ?? $this->token));
    }

    /** The shown index of an original option, from the paper the learner got. */
    private function shown(string $sittingId, string $qid, int $original): int
    {
        $paper = app(TenantContext::class)->runAs($this->ubuntu, fn () => TestSitting::query()->findOrFail($sittingId)->paper);

        return (int) array_search($original, collect($paper)->firstWhere('id', $qid)['options'], true);
    }

    public function test_the_paper_has_no_answers_and_a_resumed_sitting_shows_the_same_paper(): void
    {
        $paper = $this->start()->assertCreated()->assertJsonPath('data.seconds_left', 1200)->assertJsonPath('data.locale', 'zu');
        $body = (string) $paper->getContent();
        foreach (['Power first.', 'To confirm the fix', '"answer"', 'model_answer', 'explain'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringContainsString('Wenzani kuqala?', $body); // reviewed translation
        $again = $this->start()->assertCreated();
        $this->assertSame($paper->json('data.id'), $again->json('data.id'));
        $this->assertSame($paper->json('data.questions'), $again->json('data.questions'));
        $this->assertAudited('tests.started');
    }

    public function test_choices_are_marked_at_once_and_short_answers_wait_for_the_assessor(): void
    {
        $id = (string) $this->start()->json('data.id');
        $answers = ['qsingle1' => $this->shown($id, 'qsingle1', 1), 'qmulti01' => [$this->shown($id, 'qmulti01', 2), $this->shown($id, 'qmulti01', 0)], 'qshort01' => 'So the user can work again.'];
        $this->fresh()->putJson($this->url("/learn/sittings/{$id}"), ['answers' => $answers, 'submit' => true], $this->bearer($this->token))->assertOk()
            ->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.result', null);
        $this->fresh()->getJson($this->url('/learn/enrolments/'.$this->enrolment->id.'/assessments'), $this->bearer($this->token))->assertJsonPath('data.0.waiting_for_marking', true);

        [, $assessor] = $this->person($this->ubuntu, 'assessor');
        $review = $this->fresh()->getJson($this->url("/sittings/{$id}"), $this->bearer($assessor))->assertOk()->assertJsonPath('data.learner', 'Sipho Ndlovu');
        $short = collect($review->json('data.questions'))->firstWhere('type', 'short');
        $this->assertSame('So the user can work again.', $short['answer']);
        $this->assertSame('To confirm the fix works for the user.', $short['key']);
        $this->fresh()->postJson($this->url("/sittings/{$id}/mark"), ['marks' => []], $this->bearer($assessor))->assertUnprocessable();
        $this->fresh()->postJson($this->url("/sittings/{$id}/mark"), ['marks' => ['qshort01' => 2]], $this->bearer($assessor))->assertOk()
            ->assertJsonPath('data.score', 83)->assertJsonPath('data.result', 'competent'); // (1 + 2 + 2) / 6
        $this->assertAudited('tests.marked');
        $this->fresh()->getJson($this->url("/sittings/{$id}"), $this->bearer($this->token))->assertForbidden(); // learners cannot see the key
        $this->start()->assertUnprocessable()->assertJsonPath('errors.test.0', 'You have already passed this test.');
    }

    public function test_the_server_clock_decides_with_a_grace_period_for_answers_saved_offline(): void
    {
        $id = (string) $this->start()->json('data.id');
        $wrong = ['qsingle1' => $this->shown($id, 'qsingle1', 0)];
        $this->fresh()->putJson($this->url("/learn/sittings/{$id}"), ['answers' => $wrong], $this->bearer($this->token))->assertOk()->assertJsonPath('data.status', 'open');

        Carbon::setTestNow('2027-02-10 09:25:00'); // 5 minutes after the deadline: the phone was offline
        $late = $this->fresh()->putJson($this->url("/learn/sittings/{$id}"), ['answers' => ['qshort01' => '']], $this->bearer($this->token))->assertOk()
            ->assertJsonPath('data.status', 'marked')->assertJsonPath('data.late', true)->assertJsonPath('data.result', 'not_yet');
        $this->assertEqualsCanonicalizing(['TS-2.2', 'TS-3.2'], array_column($late->json('data.revise'), 'code')); // what to revise, never the answers

        // Second attempt, never handed in: closed with what the server has once the grace period is over.
        $second = (string) $this->start()->assertCreated()->json('data.id');
        Carbon::setTestNow('2027-02-10 10:05:00'); // deadline 09:45 + 15 minutes of grace
        $this->fresh()->getJson($this->url("/learn/sittings/{$second}"), $this->bearer($this->token))->assertOk()->assertJsonPath('data.status', 'marked')->assertJsonPath('data.score', 0);
        $this->start()->assertUnprocessable()->assertJsonPath('errors.test.0', 'You have used all your attempts. Your assessor will contact you.');
    }

    public function test_other_learners_cannot_open_or_answer_my_test(): void
    {
        $id = (string) $this->start()->json('data.id');
        [$other] = $this->enrolled();
        $this->fresh()->getJson($this->url("/learn/sittings/{$id}"), $this->bearer($other))->assertNotFound();
        $this->fresh()->putJson($this->url("/learn/sittings/{$id}"), ['answers' => []], $this->bearer($other))->assertNotFound();
        $this->fresh()->postJson($this->url("/learn/enrolments/{$this->enrolment->id}/tests/{$this->task->id}/start"), [], $this->bearer($other))->assertNotFound();
    }
}
