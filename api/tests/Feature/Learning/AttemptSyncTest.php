<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Learners\IdNumbers;
use App\Learning\ActivityCatalogue;
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
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 4.1: activities, offline-safe attempt sync, and progress from required activities. */
final class AttemptSyncTest extends AccessTestCase
{
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
            'completed' => true, 'score' => 80, 'duration_seconds' => 360, 'result' => ['blocks' => 6],
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
        $this->assertSame(80, app(TenantContext::class)->runAs($this->ubuntu, fn () => Attempt::query()->where('client_id', $changed['client_id'])->value('score')));

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
}
