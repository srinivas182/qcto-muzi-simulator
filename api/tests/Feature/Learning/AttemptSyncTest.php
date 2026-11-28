<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Learners\IdNumbers;
use App\Learning\ActivityCatalogue;
use App\Learning\LearnerNudges;
use App\Learning\ScenarioContent;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\Centre;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\Package;
use App\Models\ProgrammeVersion;
use App\Models\Tenant;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 4.1: activities, offline-safe attempt sync, and progress from required activities. */
final class AttemptSyncTest extends AccessTestCase
{
    private const SCENARIO = ['scene' => 'pc-bench', 'pass_mark' => 70, 'job' => ['minutes' => 10, 'customer' => 'Thandi'], 'steps' => [
        ['id' => 'unplug', 'type' => 'tap', 'target' => 'wall-switch', 'critical' => true, 'prompt' => 'Make it safe.', 'hints' => ['At the wall.']],
        ['id' => 'cause', 'type' => 'choose', 'prompt' => 'Cause?', 'options' => ['PSU', 'RAM'], 'answer' => 1],
        ['id' => 'fitram', 'type' => 'place', 'target' => 'ram-new', 'slot' => 'slot-a1', 'prompt' => 'Fit it.'],
    ]];

    private const RIGHT = [['step' => 'unplug', 'action' => 'tap', 'value' => 'wall-switch'], ['step' => 'cause', 'action' => 'choose', 'value' => '1'], ['step' => 'fitram', 'action' => 'place', 'value' => 'ram-new>slot-a1']];

    private ProgrammeVersion $version;

    /** @var array<string, Activity> */
    private array $a = [];

    private string $token;

    private Enrolment $enrolment;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-02-10 09:00:00');
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        $programme = (string) DB::table('programmes')->where('code', 'technical-support')->value('id');
        $this->version = ProgrammeVersion::query()->where('programme_id', $programme)->where('status', 'published')->firstOrFail();
        $codes = DB::table('outcomes')->where('programme_version_id', $this->version->id)->orderBy('position')->limit(3)->pluck('code')->all();
        $catalogue = app(ActivityCatalogue::class);
        app(TenantContext::class)->runAs(null, function () use ($catalogue, $codes): void { // HQ work
            foreach ([['L1', 'lesson', 1, true], ['L2', 'lesson', 1, true], ['S1', 'scenario', 2, true], ['X1', 'lesson', 5, false]] as $i => [$code, $kind, $weight, $required]) {
                $this->a[$code] = $catalogue->create($this->version, ['kind' => $kind, 'code' => "TS-{$code}", 'title' => "Activity {$code}", 'weight' => $weight, 'required' => $required], [$codes[$i % 3]]);
                $catalogue->publish($this->a[$code]);
            }
            $this->a['S1']->update(['content' => ScenarioContent::clean(self::SCENARIO)[0] + ['review' => ['en' => ['by' => 'HQ']]]]);
            $this->a['D1'] = $catalogue->create($this->version, ['kind' => 'lesson', 'code' => 'TS-D1', 'title' => 'Draft', 'weight' => 1], []);
        }, platform: true);
        [$this->token, $this->enrolment] = $this->enrolled($this->ubuntu, $package = Package::query()->create(['code' => 'ts', 'name' => 'TS', 'programme_id' => $programme, 'includes_simulators' => true, 'includes_courses' => true, 'billing' => 'term', 'term_months' => 12, 'min_seats' => 1]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: string, 1: Enrolment} */
    private function enrolled(Tenant $tenant, Package $package): array
    {
        [$user, $token] = $this->person($tenant, 'learner');

        return [$token, app(TenantContext::class)->runAs($tenant, function () use ($user, $package): Enrolment {
            $pool = LicencePool::query()->forceCreate(['package_id' => $package->id, 'programme_id' => $this->version->programme_id, 'billing' => 'term', 'term_months' => 12, 'unit_price_cents' => 1, 'seats' => 5, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'status' => 'active']);
            $cohort = Cohort::query()->create(['centre_id' => Centre::query()->create(['name' => 'Main'])->id, 'programme_id' => $this->version->programme_id, 'programme_version_id' => $this->version->id,
                'name' => 'TS', 'starts_on' => '2027-02-01', 'join_code' => strtoupper(Str::random(8))]);
            $learner = Learner::query()->create(['user_id' => $user->id, 'first_names' => 'Sipho', 'surname' => 'Ndlovu', 'id_type' => 'passport', 'phone' => '+2782'.random_int(1000000, 9999999), 'status' => 'active'] + IdNumbers::protect('passport', 'P'.random_int(1000000, 9999999)));

            return Enrolment::query()->create(['learner_id' => $learner->id, 'cohort_id' => $cohort->id, 'programme_id' => $this->version->programme_id, 'programme_version_id' => $this->version->id, 'licence_pool_id' => $pool->id, 'seat_taken_at' => '2027-02-01 08:00:00']);
        })];
    }

    /** @param array<string, mixed> $over */
    private function item(string $activity, array $over = []): array
    {
        return $over + [
            'client_id' => (string) Str::uuid(), 'enrolment_id' => $this->enrolment->id, 'activity_id' => $this->a[$activity]->id,
            'mode' => $this->a[$activity]->kind === 'lesson' ? 'lesson' : 'practice', 'started_at' => '2027-02-10T08:00:00Z', 'finished_at' => '2027-02-10T08:06:00Z',
            'completed' => true, 'score' => 80, 'duration_seconds' => 360, 'result' => $this->a[$activity]->kind === 'lesson' ? ['blocks' => 6] : ['events' => self::RIGHT],
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function sync(array $items, ?string $token = null, string $host = 'ubuntu.simuwork.test'): TestResponse
    {
        return $this->fresh()->postJson($this->url('/learn/attempts/sync', $host), ['attempts' => $items], $this->bearer($token ?? $this->token));
    }

    private function attempts(): int
    {
        return app(TenantContext::class)->runAs($this->ubuntu, fn () => Attempt::query()->count());
    }

    public function test_publishing_links_the_outcome_on_the_coverage_map(): void
    {
        $this->assertSame('linked', DB::table('outcome_coverage')->where('channel', 'simulator')->where('title', 'Activity S1')->value('status'));
        $this->assertSame(2, DB::table('outcome_coverage')->where('channel', 'course')->where('status', 'linked')->count());
    }

    public function test_progress_is_the_weighted_share_of_required_activities_and_drives_the_withdrawal_rule(): void
    {
        $this->sync([$this->item('S1')])->assertOk()->assertJsonPath("data.progress.{$this->enrolment->id}", 50); // weight 2 of 4
        $this->sync([$this->item('X1')])->assertOk()->assertJsonPath("data.progress.{$this->enrolment->id}", 50); // optional: no change
        $this->sync([$this->item('L1', ['completed' => false, 'finished_at' => null])])->assertJsonPath("data.progress.{$this->enrolment->id}", 50); // unfinished
        $this->sync([$this->item('L1')])->assertJsonPath("data.progress.{$this->enrolment->id}", 75);

        $e = app(TenantContext::class)->runAs($this->ubuntu, fn () => Enrolment::query()->findOrFail($this->enrolment->id));
        $this->assertSame('active', $e->status); // first activity counts as starting: the no-show rule stops
        $this->assertNotNull($e->last_activity_at);
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');
        $this->fresh()->postJson($this->url("/enrolments/{$e->id}/withdraw"), ['reason' => 'Moved'], $this->bearer($admin))->assertJsonPath('data.holds_seat', true); // past 10%
    }

    public function test_sending_the_same_attempts_again_changes_nothing_and_finished_attempts_are_final(): void
    {
        $batch = [$this->item('L1'), $this->item('S1')];
        $this->sync($batch)->assertOk()->assertJsonCount(2, 'data.accepted');
        $this->sync($batch)->assertOk()->assertJsonCount(2, 'data.accepted');
        $this->assertSame(2, $this->attempts());

        $changed = $batch[1];
        $changed['score'] = 5;
        $this->sync([$changed])->assertOk();
        // (simulator scores come from the server, so the stored score is the clean run's 100, not the device's 80)
        $this->assertSame(100, app(TenantContext::class)->runAs($this->ubuntu, fn () => Attempt::query()->where('client_id', $changed['client_id'])->value('score')));

        // An unfinished attempt can be updated until it finishes (resumed after going offline).
        $open = $this->item('L2', ['completed' => false, 'finished_at' => null, 'score' => null]);
        $this->sync([$open]);
        $this->sync([array_merge($open, ['completed' => true, 'finished_at' => '2027-02-10T08:20:00Z', 'score' => 90])])->assertJsonPath("data.progress.{$this->enrolment->id}", 100); // L1 + S1 + L2 = all required
    }

    public function test_bad_records_are_rejected_one_by_one_without_blocking_the_rest(): void
    {
        $otherVersion = $this->version->replicate();
        $otherVersion->number = 99;
        $otherVersion->status = 'draft';
        $otherVersion->save();
        $other = $this->a['L1']->replicate();
        $other->code = 'TS-OTHER';
        $other->programme_version_id = $otherVersion->id;
        $other->status = 'published';
        $other->save();

        $res = $this->sync([
            $this->item('L1'),
            $this->item('D1'),                                              // draft
            $this->item('L1', ['activity_id' => $other->id]),               // not the pinned version
            $this->item('L1', ['mode' => 'job']),                           // lessons are not jobs
            $this->item('L1', ['started_at' => '2027-03-01T08:00:00Z']),    // from the future
            $this->item('L1', ['started_at' => '2027-01-01T08:00:00Z']),    // before enrolment
            $this->item('L1', ['client_id' => 'not-a-uuid']),
            $this->item('L1', ['enrolment_id' => (string) Str::uuid()]),
        ])->assertOk()->assertJsonCount(1, 'data.accepted');
        $this->assertSame(['activity_not_in_your_programme', 'activity_not_in_your_programme', 'invalid_mode', 'invalid_time', 'invalid_time', 'invalid_client_id', 'not_your_enrolment'],
            array_column($res->json('data.rejected'), 'reason'));
    }

    public function test_learners_cannot_write_to_someone_elses_enrolment_or_attempt(): void
    {
        $item = $this->item('L1');
        $this->sync([$item])->assertOk();
        [$otherToken] = $this->enrolled($this->ubuntu, Package::query()->firstOrFail());
        $this->sync([array_merge($item, ['score' => 1])], $otherToken)->assertOk()->assertJsonPath('data.rejected.0.reason', 'not_your_enrolment');

        // Another provider's learner with the same device ID is a separate record (unique per provider).
        [$leboneToken, $leboneEnrolment] = $this->enrolled($this->lebone, Package::query()->firstOrFail());
        $this->sync([array_merge($item, ['enrolment_id' => $leboneEnrolment->id])], $leboneToken, 'lebone.simuwork.test')->assertOk()->assertJsonCount(1, 'data.accepted');
        $this->assertSame(1, $this->attempts());
    }

    public function test_the_learner_sees_each_activity_with_their_status(): void
    {
        $this->sync([$this->item('L1'), $this->item('S1', ['completed' => false, 'finished_at' => null])]);
        $res = $this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/activities"), $this->bearer($this->token))->assertOk()
            ->assertJsonPath('data.progress', 25)->assertJsonCount(4, 'data.activities');
        $this->assertSame(['done', 'new', 'started', 'new'], array_column($res->json('data.activities'), 'status'));

        [$otherToken] = $this->enrolled($this->ubuntu, Package::query()->firstOrFail());
        $this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/activities"), $this->bearer($otherToken))->assertNotFound();
    }

    public function test_the_lesson_comes_in_the_learners_language_only_when_reviewed(): void
    {
        $blocks = [['id' => 'b1abcdef', 'type' => 'narration', 'script' => 'Hello', 'audio_asset_id' => null]];
        $this->a['L1']->update(['content' => ['blocks' => $blocks, 'i18n' => ['zu' => ['b1abcdef' => ['script' => 'Sawubona']], 'xh' => ['b1abcdef' => ['script' => 'Molo']]],
            'review' => ['en' => ['by' => 'HQ'], 'zu' => ['by' => 'HQ'], 'xh' => ['ai' => true]]]]);
        $url = fn (string $loc) => $this->url("/learn/enrolments/{$this->enrolment->id}/lessons/{$this->a['L1']->id}?locale={$loc}");
        $this->fresh()->getJson($url('zu'), $this->bearer($this->token))->assertOk()->assertJsonPath('data.blocks.0.script', 'Sawubona')->assertJsonPath('data.locale', 'zu');
        $this->fresh()->getJson($url('xh'), $this->bearer($this->token))->assertOk()->assertJsonPath('data.blocks.0.script', 'Hello')->assertJsonPath('data.locale', 'en');

        $this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/lessons/{$this->a['D1']->id}"), $this->bearer($this->token))->assertNotFound(); // draft
        $this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/lessons/{$this->a['S1']->id}"), $this->bearer($this->token))->assertNotFound(); // a scenario
        [$otherToken] = $this->enrolled($this->ubuntu, Package::query()->firstOrFail());
        $this->fresh()->getJson($url('en'), $this->bearer($otherToken))->assertNotFound();
    }

    public function test_the_continue_card_points_to_the_first_required_activity_not_done(): void
    {
        $home = fn () => $this->fresh()->getJson($this->url('/learn/home'), $this->bearer($this->token))->assertOk();
        $home()->assertJsonPath('data.enrolments.0.next.title', 'Activity L1')->assertJsonPath('data.enrolments.0.next.started', false);
        $this->sync([$this->item('L1'), $this->item('L2', ['completed' => false, 'finished_at' => null])]);
        $home()->assertJsonPath('data.enrolments.0.next.title', 'Activity L2')->assertJsonPath('data.enrolments.0.next.started', true);
        $this->sync([$this->item('L2'), $this->item('S1')]);
        $home()->assertJsonPath('data.enrolments.0.next.title', 'Activity X1'); // then optional ones
        $this->sync([$this->item('X1')]);
        $home()->assertJsonPath('data.enrolments.0.next', null);
    }

    public function test_simulator_scores_are_worked_out_on_the_server_from_the_learners_moves(): void
    {
        $find = fn (string $id) => app(TenantContext::class)->runAs($this->ubuntu, fn () => Attempt::query()->where('client_id', $id)->firstOrFail());
        // A clean run scores 100 whatever the device claims.
        $this->sync([$clean = $this->item('S1', ['score' => 3, 'completed' => false])])->assertJsonPath('data.accepted.0', $clean['client_id']);
        $a = $find($clean['client_id']);
        $this->assertSame([100, true, true], [$a->score, $a->completed, $a->result['passed']]);

        // A claimed 100 with skipped steps is not completed.
        $this->sync([$skip = $this->item('S1', ['score' => 100, 'result' => ['events' => [self::RIGHT[2]]]])]);
        $this->assertSame([0, false], [$find($skip['client_id'])->score, $find($skip['client_id'])->completed]);

        // One hint and one wrong choice in practice: (0.85 + 0.75 + 1) / 3 = 87, passed.
        $events = [['step' => 'unplug', 'action' => 'hint'], self::RIGHT[0], ['step' => 'cause', 'action' => 'choose', 'value' => '0'], self::RIGHT[1], self::RIGHT[2]];
        $this->sync([$p = $this->item('S1', ['result' => ['events' => $events]])]);
        $this->assertSame([87, true], [$find($p['client_id'])->score, $find($p['client_id'])->result['passed']]);

        // A safety mistake fails real-job mode even with a good score.
        $events = [['step' => 'unplug', 'action' => 'tap', 'value' => 'case-panel'], ...self::RIGHT];
        $this->sync([$j = $this->item('S1', ['mode' => 'job', 'result' => ['events' => $events]])]);
        $this->assertSame([92, false, true], [$find($j['client_id'])->score, $find($j['client_id'])->result['passed'], $find($j['client_id'])->result['critical']]);
    }

    public function test_the_scenario_comes_with_lesson_links_and_the_learners_best_results(): void
    {
        $this->a['S1']->update(['content' => ScenarioContent::clean(self::SCENARIO)[0] + ['i18n' => ['zu' => ['steps' => ['unplug' => ['prompt' => 'Yenza kuphephe.']]]], 'review' => ['en' => ['by' => 'HQ'], 'zu' => ['by' => 'HQ']]]]);
        $url = fn (string $loc = 'en') => $this->url("/learn/enrolments/{$this->enrolment->id}/scenarios/{$this->a['S1']->id}?locale={$loc}");
        $this->fresh()->getJson($url(), $this->bearer($this->token))->assertOk()
            ->assertJsonPath('data.content.scene', 'pc-bench')->assertJsonPath('data.content.steps.0.prompt', 'Make it safe.')
            ->assertJsonPath('data.lessons.TS-L1', $this->a['L1']->id)->assertJsonPath('data.results.practice', null)
            ->assertJsonMissingPath('data.content.i18n');
        $this->fresh()->getJson($url('zu'), $this->bearer($this->token))->assertJsonPath('data.content.steps.0.prompt', 'Yenza kuphephe.');

        $this->sync([$this->item('S1')]);
        $this->fresh()->getJson($url(), $this->bearer($this->token))->assertJsonPath('data.results.practice.best', 100)->assertJsonPath('data.results.practice.passed', true);
        $this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/scenarios/{$this->a['L1']->id}"), $this->bearer($this->token))->assertNotFound();
    }

    public function test_the_facilitator_board_shows_every_learner_and_activity_with_at_risk_first(): void
    {
        [, $staff] = $this->person($this->ubuntu, 'facilitator');
        [$quietToken, $quiet] = $this->enrolled($this->ubuntu, Package::query()->firstOrFail());
        $this->sync([$this->item('L1'), $this->item('S1', ['mode' => 'job'])]);
        Carbon::setTestNow('2027-02-20 09:00:00'); // ten days later
        $this->fresh()->postJson($this->url('/learn/attempts/sync'), ['attempts' => [$this->item('L1', ['enrolment_id' => $quiet->id, 'started_at' => '2027-02-20T08:00:00Z', 'finished_at' => '2027-02-20T08:05:00Z'])]], $this->bearer($quietToken))->assertOk();
        // The first learner has now been quiet for 10 days; the second was active today, but in another cohort.
        $board = $this->fresh()->getJson($this->url("/cohorts/{$this->enrolment->cohort_id}/progress"), $this->bearer($staff))->assertOk();
        $board->assertJsonPath('data.summary.learners', 1)->assertJsonPath('data.summary.at_risk', 1)
            ->assertJsonPath('data.learners.0.at_risk', true)
            ->assertJsonPath("data.learners.0.cells.{$this->a['S1']->id}.job_passed", true)
            ->assertJsonPath("data.learners.0.cells.{$this->a['L2']->id}", null)
            ->assertJsonPath('data.learners.0.job_ready.shown', 1);
        $this->assertCount(4, $board->json('data.activities')); // published only
        $this->fresh()->getJson($this->url("/cohorts/{$this->enrolment->cohort_id}"), $this->bearer($staff))->assertJsonPath('data.enrolments.0.at_risk', true);
        $this->fresh()->getJson($this->url("/cohorts/{$this->enrolment->cohort_id}/progress"), $this->bearer($this->token))->assertForbidden(); // learners cannot
    }

    public function test_the_job_ready_checklist_marks_outcomes_shown_or_practised(): void
    {
        $codes = DB::table('activity_outcomes')->whereIn('activity_id', [$this->a['L1']->id, $this->a['S1']->id])->pluck('outcome_code', 'activity_id');
        $this->sync([$this->item('L1', ['score' => 90]), $this->item('S1', ['mode' => 'job'])]);
        $areas = $this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/checklist"), $this->bearer($this->token))->assertOk()->json('data.areas');
        $status = collect($areas)->flatMap(fn ($a) => $a['outcomes'])->pluck('status', 'code');
        $this->assertSame('shown', $status[$codes[$this->a['S1']->id]]);
        if ($codes[$this->a['L1']->id] !== $codes[$this->a['S1']->id]) {
            $this->assertSame('practised', $status[$codes[$this->a['L1']->id]]);
        }
        $this->assertSame('not_yet', $status->last());

        [, $staff] = $this->person($this->ubuntu, 'facilitator');
        $this->fresh()->getJson($this->url("/enrolments/{$this->enrolment->id}/checklist"), $this->bearer($staff))->assertOk()->assertJsonPath('data.learner', 'Sipho Ndlovu');
        [$other] = $this->enrolled($this->ubuntu, Package::query()->firstOrFail());
        $this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/checklist"), $this->bearer($other))->assertNotFound();
    }

    public function test_quiet_learners_get_a_gentle_reminder_in_their_language_at_most_weekly_and_three_times(): void
    {
        config(['identity.messaging.driver_override' => 'log']);
        $this->sync([$this->item('L1')]);
        app(TenantContext::class)->runAs($this->ubuntu, function (): void {
            $this->enrolment->refresh()->learner->user->update(['locale' => 'zu']);
        });
        $run = fn () => app(TenantContext::class)->runAs($this->ubuntu, fn () => app(LearnerNudges::class)->run($this->ubuntu));
        $this->assertSame(0, $run()); // active today: nothing

        Carbon::setTestNow('2027-02-18 09:12:00'); // 8 days quiet
        $this->assertSame(1, $run());
        $this->assertSame(0, $run()); // not twice in a week
        $this->assertAudited('learning.reminder_sent');
        Carbon::setTestNow('2027-02-26 09:12:00');
        $this->assertSame(1, $run());
        Carbon::setTestNow('2027-03-06 09:12:00');
        $this->assertSame(1, $run());
        Carbon::setTestNow('2027-03-14 09:12:00');
        $this->assertSame(0, $run()); // three in a row: the facilitator follows up in person

        // Turning reminders off is respected; the facilitator's nudge too.
        $this->fresh()->patchJson($this->url('/learn/me/reminders'), ['on' => false], $this->bearer($this->token))->assertOk()->assertJsonPath('data.on', false);
        $this->fresh()->getJson($this->url('/learn/home'), $this->bearer($this->token))->assertJsonPath('data.learner.reminders', false);
        [, $staff] = $this->person($this->ubuntu, 'tp_admin');
        $this->fresh()->postJson($this->url("/enrolments/{$this->enrolment->id}/nudge"), [], $this->bearer($staff))->assertUnprocessable()->assertJsonPath('data.sent', false);
    }

    public function test_the_reminder_text_is_short_and_in_each_language(): void
    {
        foreach (LearnerNudges::TEXT as $text) {
            $this->assertStringContainsString('{url}', $text);
            $this->assertLessThan(200, mb_strlen(strtr($text, ['{academy}' => 'Ubuntu Skills Academy', '{name}' => 'Sipho', '{programme}' => 'Technical Support', '{url}' => ''])));
        }
    }

    public function test_a_learner_shares_a_read_only_checklist_link_and_can_stop_it(): void
    {
        $this->sync([$this->item('S1', ['mode' => 'job'])]);
        $made = $this->fresh()->postJson($this->url("/learn/enrolments/{$this->enrolment->id}/shares"), ['label' => 'For my mentor'], $this->bearer($this->token))->assertCreated();
        $token = (string) str($made->json('data.url'))->afterLast('/c/');
        $this->assertSame(40, strlen($token));
        $this->assertAudited('checklist.shared');

        $view = $this->fresh()->getJson($this->url("/checklists/{$token}"))->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
        $view->assertJsonPath('data.learner', 'Sipho N.')->assertJsonPath('data.academy', $this->ubuntu->name);
        $this->assertStringNotContainsString('Ndlovu', (string) $view->getContent()); // no full surname, no ID, no phone
        $this->assertStringNotContainsString('+2782', (string) $view->getContent());
        $this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/shares"), $this->bearer($this->token))->assertJsonPath('data.0.views', 1)->assertJsonPath('data.0.label', 'For my mentor');

        // Another provider's host cannot open it, and once stopped it is gone.
        $this->fresh()->getJson($this->url("/checklists/{$token}", 'lebone.simuwork.test'))->assertNotFound();
        [$other] = $this->enrolled($this->ubuntu, Package::query()->firstOrFail());
        $this->fresh()->deleteJson($this->url('/learn/shares/'.$made->json('data.id')), [], $this->bearer($other))->assertNotFound();
        $this->fresh()->deleteJson($this->url('/learn/shares/'.$made->json('data.id')), [], $this->bearer($this->token))->assertNoContent();
        $this->fresh()->getJson($this->url("/checklists/{$token}"))->assertNotFound();
        Carbon::setTestNow('2027-04-10 09:00:00');
        $this->fresh()->getJson($this->url('/checklists/'.str_repeat('a', 40)))->assertNotFound();
    }

    public function test_the_offline_pack_holds_the_whole_programme_and_says_when_nothing_changed(): void
    {
        $this->a['L1']->update(['content' => ['blocks' => [['id' => 'b1abcdef', 'type' => 'narration', 'script' => 'Hello', 'audio_asset_id' => null]], 'i18n' => ['zu' => ['b1abcdef' => ['script' => 'Sawubona']]], 'review' => ['en' => ['by' => 'HQ'], 'zu' => ['by' => 'HQ']]]]);
        $url = $this->url("/learn/enrolments/{$this->enrolment->id}/pack?locale=zu");
        $pack = $this->fresh()->getJson($url, $this->bearer($this->token))->assertOk();
        $pack->assertJsonPath("data.lessons.{$this->a['L1']->id}.blocks.0.script", 'Sawubona')
            ->assertJsonPath("data.scenarios.{$this->a['S1']->id}.content.scene", 'pc-bench')
            ->assertJsonPath('data.runtime.url', fn ($u) => str_ends_with($u, '/embed/sim-3d.html'))
            ->assertJsonMissingPath("data.lessons.{$this->a['D1']->id}")
            ->assertJsonMissingPath("data.scenarios.{$this->a['S1']->id}.results"); // personal results stay online
        $etag = (string) $pack->headers->get('ETag');
        $this->fresh()->getJson($url, $this->bearer($this->token) + ['If-None-Match' => $etag])->assertStatus(304);

        $this->a['L1']->update(['title' => 'Safety first', 'content_version' => 2]);
        $this->fresh()->getJson($url, $this->bearer($this->token) + ['If-None-Match' => $etag])->assertOk()->assertJsonPath("data.lessons.{$this->a['L1']->id}.title", 'Safety first');
        [$other] = $this->enrolled($this->ubuntu, Package::query()->firstOrFail());
        $this->fresh()->getJson($url, $this->bearer($other))->assertNotFound();
    }

    public function test_the_coach_answers_in_the_learners_language_without_personal_data_and_falls_back_to_the_author(): void
    {
        $url = $this->url("/learn/enrolments/{$this->enrolment->id}/scenarios/{$this->a['S1']->id}/coach");
        config(['services.anthropic.key' => '']);
        $this->fresh()->postJson($url, ['step' => 'unplug', 'kind' => 'help'], $this->bearer($this->token))->assertOk()
            ->assertJsonPath('data.source', 'author')->assertJsonPath('data.answer', 'At the wall.');

        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{"answer":"Cabanga ngogesi kuqala."}']]])]);
        $ask = fn () => $this->fresh()->postJson($url, ['step' => 'unplug', 'kind' => 'why', 'locale' => 'zu'], $this->bearer($this->token));
        $ask()->assertOk()->assertJsonPath('data.source', 'ai')->assertJsonPath('data.answer', 'Cabanga ngogesi kuqala.');
        $ask()->assertOk();
        Http::assertSentCount(1); // cached per step and language
        Http::assertSent(function ($r): bool {
            $body = (string) json_encode($r->data());

            return str_contains($body, 'isiZulu') && str_contains($body, 'Make it safe.') && ! str_contains($body, 'Sipho') && ! str_contains($body, 'Ndlovu');
        });

        [$other] = $this->enrolled($this->ubuntu, Package::query()->firstOrFail());
        $this->fresh()->postJson($url, ['step' => 'unplug', 'kind' => 'why'], $this->bearer($other))->assertNotFound();
    }

    public function test_asking_the_coach_for_help_costs_like_a_hint_and_asking_why_is_free(): void
    {
        [$content] = ScenarioContent::clean(self::SCENARIO);
        $events = [['step' => 'unplug', 'action' => 'coach', 'value' => 'why'], ...self::RIGHT];
        $this->assertSame(100, ScenarioContent::score($content, $events, 'practice')['score']);
        $events = [['step' => 'unplug', 'action' => 'coach', 'value' => 'help'], ...self::RIGHT];
        $this->assertSame(95, ScenarioContent::score($content, $events, 'practice')['score']); // (0.85 + 1 + 1) / 3
    }
}
