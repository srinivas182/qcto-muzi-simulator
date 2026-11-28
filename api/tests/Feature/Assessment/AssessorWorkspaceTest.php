<?php

declare(strict_types=1);

namespace Tests\Feature\Assessment;

use App\Learners\IdNumbers;
use App\Learning\ScenarioContent;
use App\Models\Activity;
use App\Models\AssessmentDecision;
use App\Models\AssessmentTask;
use App\Models\Attempt;
use App\Models\Centre;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\Package;
use App\Models\ProgrammeVersion;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 5.2: the assessor's queue, the step-by-step replay of a real-job run, and decisions with feedback. */
final class AssessorWorkspaceTest extends AccessTestCase
{
    private const SCENARIO = ['scene' => 'pc-bench', 'pass_mark' => 70, 'job' => ['minutes' => 10, 'customer' => 'Thandi'], 'steps' => [
        ['id' => 'unplug', 'type' => 'tap', 'target' => 'wall-switch', 'critical' => true, 'prompt' => 'Make it safe.', 'lesson' => ['code' => 'TS-L1']],
        ['id' => 'cause', 'type' => 'choose', 'prompt' => 'Cause?', 'options' => ['PSU', 'RAM'], 'answer' => 1],
    ]];

    private ProgrammeVersion $version;

    private AssessmentTask $sim;

    private AssessmentTask $obs;

    private Activity $scenario;

    private Enrolment $enrolment;

    private string $assessor;

    private string $learnerToken;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 09:00:00');
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        $programme = (string) DB::table('programmes')->where('code', 'technical-support')->value('id');
        $this->version = ProgrammeVersion::query()->where('programme_id', $programme)->where('status', 'published')->firstOrFail();
        Activity::query()->create(['programme_version_id' => $this->version->id, 'kind' => 'lesson', 'code' => 'TS-L1', 'title' => 'Working safely', 'position' => 1, 'status' => 'published', 'content' => [], 'content_version' => 1]);
        $this->scenario = Activity::query()->create(['programme_version_id' => $this->version->id, 'kind' => 'scenario', 'code' => 'TS-S1', 'title' => 'Slow PC', 'position' => 2, 'status' => 'published', 'content' => ScenarioContent::clean(self::SCENARIO)[0], 'content_version' => 1]);
        $this->sim = AssessmentTask::query()->create(['programme_version_id' => $this->version->id, 'code' => 'TS-P01', 'title' => 'Fix the slow PC', 'component' => 'practical', 'kind' => 'simulator', 'activity_id' => $this->scenario->id, 'position' => 1, 'settings' => ['mode' => 'job', 'attempts' => 2], 'status' => 'published', 'content' => []]);
        $this->obs = AssessmentTask::query()->create(['programme_version_id' => $this->version->id, 'code' => 'TS-P02', 'title' => 'Observed repair', 'component' => 'practical', 'kind' => 'observation', 'position' => 2, 'settings' => ['items' => ['Works safely', 'Explains the fix to the user']], 'status' => 'published', 'content' => []]);
        $this->enrolment = $this->enrolled();
        [, $this->assessor] = $this->person($this->ubuntu, 'assessor');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enrolled(): Enrolment
    {
        [$user, $this->learnerToken] = $this->person($this->ubuntu, 'learner');
        $package = Package::query()->create(['code' => 'ts'.Str::random(4), 'name' => 'TS', 'programme_id' => $this->version->programme_id, 'includes_simulators' => true, 'includes_courses' => true, 'billing' => 'term', 'term_months' => 12, 'min_seats' => 1]);

        return app(TenantContext::class)->runAs($this->ubuntu, function () use ($user, $package): Enrolment {
            $pool = LicencePool::query()->forceCreate(['package_id' => $package->id, 'programme_id' => $this->version->programme_id, 'billing' => 'term', 'term_months' => 12, 'unit_price_cents' => 1, 'seats' => 5, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'status' => 'active']);
            $cohort = Cohort::query()->create(['centre_id' => Centre::query()->create(['name' => 'Main'])->id, 'programme_id' => $this->version->programme_id, 'programme_version_id' => $this->version->id, 'name' => 'TS Feb', 'starts_on' => '2027-02-01', 'join_code' => strtoupper(Str::random(8))]);
            $learner = Learner::query()->create(['user_id' => $user->id, 'first_names' => 'Sipho', 'surname' => 'Ndlovu', 'id_type' => 'passport', 'phone' => '+2782'.random_int(1000000, 9999999), 'status' => 'active'] + IdNumbers::protect('passport', 'P'.random_int(1000000, 9999999)));

            return Enrolment::query()->create(['learner_id' => $learner->id, 'cohort_id' => $cohort->id, 'programme_id' => $this->version->programme_id, 'programme_version_id' => $this->version->id, 'licence_pool_id' => $pool->id, 'seat_taken_at' => '2027-02-01 08:00:00', 'status' => 'active']);
        });
    }

    /** @param list<array<string, mixed>> $events */
    private function jobRun(array $events): Attempt
    {
        $scored = ScenarioContent::score($this->scenario->content, $events, 'job');

        return app(TenantContext::class)->runAs($this->ubuntu, fn () => Attempt::query()->create([
            'client_id' => (string) Str::uuid(), 'enrolment_id' => $this->enrolment->id, 'activity_id' => $this->scenario->id, 'mode' => 'job', 'content_version' => 1,
            'started_at' => now()->subMinutes(8), 'finished_at' => now(), 'completed' => $scored['completed'], 'score' => $scored['score'], 'duration_seconds' => 480,
            'result' => ['events' => $events, 'variant' => null, 'passed' => $scored['passed'], 'critical' => $scored['critical'], 'steps' => $scored['steps']], 'device' => 'mobile',
        ]));
    }

    private function decide(array $body, ?AssessmentTask $task = null, ?string $token = null): TestResponse
    {
        $task ??= $this->sim;

        return $this->fresh()->postJson($this->url("/assess/enrolments/{$this->enrolment->id}/tasks/{$task->id}/decisions"), $body + ['declaration' => true], $this->bearer($token ?? $this->assessor));
    }

    public function test_a_run_is_queued_replayed_step_by_step_and_judged_with_feedback_the_learner_sees(): void
    {
        $bad = $this->jobRun([['step' => 'unplug', 'action' => 'tap', 'value' => 'case-lid', 't' => 4000], ['step' => 'unplug', 'action' => 'tap', 'value' => 'wall-switch', 't' => 9000], ['step' => 'cause', 'action' => 'choose', 'value' => '1', 't' => 20000]]);

        $queue = $this->fresh()->getJson($this->url('/assess/queue'), $this->bearer($this->assessor))->assertOk();
        $queue->assertJsonPath('data.runs.0.id', $bad->id)->assertJsonPath('data.runs.0.critical', true)->assertJsonPath('data.runs.0.learner', 'Sipho Ndlovu')->assertJsonPath('data.runs.0.round', 1);
        $queue->assertJsonPath('data.observe.0.ready', false); // the simulator task is not yet competent

        $replay = $this->fresh()->getJson($this->url("/assess/attempts/{$bad->id}"), $this->bearer($this->assessor))->assertOk();
        $replay->assertJsonPath('data.steps.0.mistakes', 1)->assertJsonPath('data.steps.0.moves.0.right', false)->assertJsonPath('data.steps.0.moves.1.right', true)
            ->assertJsonPath('data.steps.1.moves.0.value', 'RAM')->assertJsonPath('data.task.code', 'TS-P01')->assertJsonPath('data.task.link_options.lessons.0.code', 'TS-L1');
        $hash = (string) $replay->json('data.hash');
        $this->assertSame(64, strlen($hash));

        // Not yet competent needs feedback; links must point at this simulator's steps or the programme's lessons.
        $this->decide(['result' => 'not_yet', 'attempt_id' => $bad->id])->assertUnprocessable()->assertJsonValidationErrors('feedback');
        $this->decide(['result' => 'competent', 'attempt_id' => $bad->id], token: $this->person($this->ubuntu, 'moderator')[1])->assertForbidden(); // moderators review, they do not sign off
        $this->decide(['result' => 'not_yet', 'attempt_id' => $bad->id, 'feedback' => 'Switch off at the wall before you open the case.',
            'links' => [['type' => 'step', 'ref' => 'unplug'], ['type' => 'lesson', 'ref' => 'TS-L1'], ['type' => 'lesson', 'ref' => 'NOPE']]])
            ->assertCreated()->assertJsonPath('data.round', 1)->assertJsonCount(2, 'data.links')->assertJsonPath('data.hash', $hash);
        $this->assertAudited('assessments.decided');
        $this->decide(['result' => 'competent', 'attempt_id' => $bad->id])->assertUnprocessable()->assertJsonValidationErrors('attempt_id'); // one decision per run

        $this->fresh()->getJson($this->url("/assess/attempts/{$bad->id}"), $this->bearer($this->assessor))->assertJsonPath('data.decision.hash_matches', true);
        $mine = collect($this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/assessments"), $this->bearer($this->learnerToken))->assertOk()->json('data'))->firstWhere('code', 'TS-P01');
        $this->assertSame('not_yet', $mine['result']);
        $this->assertSame('Switch off at the wall before you open the case.', $mine['feedback']['text']);
        $this->assertSame('unplug', $mine['feedback']['links'][0]['ref']);

        // Second and last opportunity: competent. Then the observation is ready.
        Carbon::setTestNow('2027-02-12 09:00:00');
        $good = $this->jobRun([['step' => 'unplug', 'action' => 'tap', 'value' => 'wall-switch', 't' => 3000], ['step' => 'cause', 'action' => 'choose', 'value' => '1', 't' => 9000]]);
        $this->decide(['result' => 'competent', 'attempt_id' => $good->id])->assertCreated()->assertJsonPath('data.round', 2);
        $queue = $this->fresh()->getJson($this->url('/assess/queue'), $this->bearer($this->assessor))->assertOk();
        $queue->assertJsonCount(0, 'data.runs')->assertJsonPath('data.observe.0.ready', true)->assertJsonCount(2, 'data.done');
        $this->decide(['result' => 'competent', 'attempt_id' => $good->id])->assertUnprocessable()->assertJsonValidationErrors('result'); // already competent
    }

    public function test_the_observation_checklist_needs_every_item_and_opportunities_are_limited(): void
    {
        $this->fresh()->getJson($this->url("/assess/enrolments/{$this->enrolment->id}/tasks/{$this->obs->id}"), $this->bearer($this->assessor))
            ->assertOk()->assertJsonPath('data.items.1', 'Explains the fix to the user')->assertJsonPath('data.rounds_allowed', 3);
        $this->decide(['result' => 'competent', 'observation' => [['met' => true]]], $this->obs)->assertUnprocessable()->assertJsonValidationErrors('observation');
        $this->decide(['result' => 'competent', 'observation' => [['met' => true], ['met' => false]]], $this->obs)->assertUnprocessable()->assertJsonValidationErrors('result');
        $this->fresh()->postJson($this->url("/assess/enrolments/{$this->enrolment->id}/tasks/{$this->obs->id}/decisions"), ['result' => 'competent', 'observation' => [['met' => true], ['met' => true]], 'declaration' => false], $this->bearer($this->assessor))
            ->assertUnprocessable()->assertJsonValidationErrors('declaration');
        foreach ([1, 2, 3] as $round) {
            $this->decide(['result' => 'not_yet', 'feedback' => 'Explain in plain words.', 'observation' => [['met' => true], ['met' => false]]], $this->obs)->assertCreated()->assertJsonPath('data.round', $round);
        }
        $this->decide(['result' => 'not_yet', 'feedback' => 'Again.', 'observation' => [['met' => true], ['met' => false]]], $this->obs)
            ->assertUnprocessable()->assertJsonValidationErrors('result'); // all three opportunities used
        $this->fresh()->getJson($this->url('/assess/queue'), $this->bearer($this->assessor))->assertOk()->assertJsonMissing(['id' => $this->enrolment->id.'|'.$this->obs->id]);
    }

    public function test_assessors_of_another_provider_see_nothing(): void
    {
        $run = $this->jobRun([['step' => 'unplug', 'action' => 'tap', 'value' => 'wall-switch', 't' => 1]]);
        [, $other] = $this->person($this->lebone, 'assessor');
        $this->fresh()->getJson($this->url('/assess/queue', 'lebone.simuwork.test'), $this->bearer($other))->assertOk()->assertJsonCount(0, 'data.runs');
        $this->fresh()->getJson($this->url("/assess/attempts/{$run->id}", 'lebone.simuwork.test'), $this->bearer($other))->assertNotFound();
        $this->fresh()->getJson($this->url('/assess/queue'), $this->bearer($this->person($this->ubuntu, 'learner')[1]))->assertForbidden();
    }

    public function test_an_observation_signed_off_offline_is_recorded_once_and_accommodations_are_set_without_a_reason(): void
    {
        $body = ['result' => 'competent', 'observation' => [['met' => true], ['met' => true]], 'client_id' => (string) Str::uuid(), 'declared_at' => '2027-02-10T08:30:00Z'];
        $first = $this->decide($body, $this->obs)->assertCreated();
        $this->decide($body, $this->obs)->assertCreated()->assertJsonPath('data.id', $first->json('data.id')); // retried after the signal came back
        $this->assertSame(1, app(TenantContext::class)->runAs($this->ubuntu, fn () => AssessmentDecision::query()->count()));
        $this->assertSame('2027-02-10 08:30:00', app(TenantContext::class)->runAs($this->ubuntu, fn () => AssessmentDecision::query()->firstOrFail()->declared_at->format('Y-m-d H:i:s')));

        $url = $this->url("/assess/enrolments/{$this->enrolment->id}/accommodations");
        $this->fresh()->putJson($url, ['extra_time' => 50, 'read_aloud' => true], $this->bearer($this->person($this->ubuntu, 'moderator')[1]))->assertForbidden();
        $this->fresh()->putJson($url, ['extra_time' => 40, 'read_aloud' => true], $this->bearer($this->assessor))->assertUnprocessable();
        $this->fresh()->putJson($url, ['extra_time' => 50, 'read_aloud' => true, 'reason' => 'dyslexia'], $this->bearer($this->assessor))->assertOk()->assertJsonPath('data.extra_time', 50);
        $this->assertAudited('assessments.accommodations');
        $stored = app(TenantContext::class)->runAs($this->ubuntu, fn () => DB::table('enrolments')->where('id', $this->enrolment->id)->value('accommodations'));
        $this->assertStringNotContainsString('dyslexia', (string) $stored);
        $this->fresh()->getJson($url, $this->bearer($this->assessor))->assertJsonPath('data.read_aloud', true);
    }
}
