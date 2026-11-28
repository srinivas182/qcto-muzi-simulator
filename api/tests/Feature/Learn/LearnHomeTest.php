<?php

declare(strict_types=1);

namespace Tests\Feature\Learn;

use App\Billing\BillingRun;
use App\Learners\IdNumbers;
use App\Models\Centre;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\Package;
use App\Models\User;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 3.6c: learner home, starting a programme, own QCTO details, and seat health on licences. */
final class LearnHomeTest extends AccessTestCase
{
    private string $learnerToken;

    private string $admin;

    private Enrolment $enrolment;

    private User $learnerUser;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2027-02-01 09:00:00');
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        [, $this->admin] = $this->person($this->ubuntu, 'tp_admin');
        [$this->learnerUser, $this->learnerToken] = $this->person($this->ubuntu, 'learner');
        [$facilitator] = $this->person($this->ubuntu, 'facilitator');
        $programme = (string) DB::table('programmes')->where('code', 'technical-support')->value('id');
        $package = Package::query()->create(['code' => 'ts', 'name' => 'TS', 'programme_id' => $programme, 'includes_simulators' => true, 'includes_courses' => true, 'billing' => 'term', 'term_months' => 12, 'min_seats' => 1]);
        $this->enrolment = app(TenantContext::class)->runAs($this->ubuntu, function () use ($programme, $package, $facilitator): Enrolment {
            $pool = LicencePool::query()->forceCreate(['package_id' => $package->id, 'programme_id' => $programme, 'billing' => 'term', 'term_months' => 12, 'unit_price_cents' => 1, 'seats' => 5, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'status' => 'active']);
            $version = DB::table('programme_versions')->where('programme_id', $programme)->where('status', 'published')->value('id');
            $cohort = Cohort::query()->create(['centre_id' => Centre::query()->create(['name' => 'Durban campus'])->id, 'programme_id' => $programme, 'programme_version_id' => $version,
                'name' => 'TS February', 'starts_on' => '2027-02-03', 'ends_on' => '2027-12-03', 'join_code' => 'HOMECODE']);
            $cohort->facilitators()->attach($facilitator->id, ['tenant_id' => $this->ubuntu->id]);
            $learner = Learner::query()->create(['user_id' => $this->learnerUser->id, 'first_names' => 'Sipho', 'surname' => 'Ndlovu', 'id_type' => 'sa_id', 'citizenship' => 'sa',
                'date_of_birth' => '1980-01-01', 'gender' => 'male', 'home_language' => 'zu', 'phone' => '+27825550101', 'status' => 'active'] + IdNumbers::protect('sa_id', '8001015009087'));

            return Enrolment::query()->create(['learner_id' => $learner->id, 'cohort_id' => $cohort->id, 'programme_id' => $programme, 'programme_version_id' => $version, 'licence_pool_id' => $pool->id, 'seat_taken_at' => now()]);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_learner_sees_their_programme_cohort_and_days_left(): void
    {
        $this->fresh()->getJson($this->url('/learn/home'), $this->bearer($this->learnerToken))->assertOk()
            ->assertJsonPath('data.learner.name', 'Sipho Ndlovu')
            ->assertJsonPath('data.enrolments.0.programme.title', 'Technical Support')
            ->assertJsonPath('data.enrolments.0.cohort.centre', 'Durban campus')
            ->assertJsonCount(1, 'data.enrolments.0.cohort.facilitators')
            ->assertJsonPath('data.enrolments.0.days_left', 305)
            ->assertJsonPath('data.enrolments.0.started', false)
            ->assertJsonPath('data.enrolments.0.seat_returns_on', '2027-02-17');

        [, $stranger] = $this->person($this->ubuntu, 'learner');
        $this->fresh()->getJson($this->url('/learn/home'), $this->bearer($stranger))->assertOk()->assertJsonPath('data.learner', null)->assertJsonPath('data.enrolments', []);
        $this->fresh()->getJson($this->url('/learn/home'), $this->bearer($this->admin))->assertForbidden();
    }

    public function test_starting_keeps_the_seat_and_only_the_owner_can_start(): void
    {
        [, $other] = $this->person($this->ubuntu, 'learner');
        app(TenantContext::class)->runAs($this->ubuntu, fn () => Learner::query()->create(['user_id' => User::query()->latest('created_at')->value('id'), 'first_names' => 'Other', 'surname' => 'Learner',
            'id_type' => 'passport', 'phone' => '+27825550109', 'status' => 'active'] + IdNumbers::protect('passport', 'A9999999')));
        $this->fresh()->postJson($this->url("/learn/enrolments/{$this->enrolment->id}/start"), [], $this->bearer($other))->assertNotFound();

        $this->fresh()->postJson($this->url("/learn/enrolments/{$this->enrolment->id}/start"), [], $this->bearer($this->learnerToken))->assertOk()->assertJsonPath('data.status', 'active');
        Carbon::setTestNow('2027-03-01 09:00:00');
        app(BillingRun::class)->run();
        $this->assertTrue(app(TenantContext::class)->runAs($this->ubuntu, fn () => Enrolment::query()->findOrFail($this->enrolment->id)->holds_seat));
    }

    public function test_learners_fill_in_their_own_qcto_details(): void
    {
        $this->fresh()->getJson($this->url('/learn/me/qcto'), $this->bearer($this->learnerToken))->assertOk()->assertJsonPath('data.citizen_status', 'SA');
        $this->fresh()->patchJson($this->url('/learn/me/qcto'), ['title' => 'Mr', 'equity' => 'BA', 'disability_status' => 'N'], $this->bearer($this->learnerToken))->assertOk()
            ->assertJsonPath('data.equity', 'BA')->assertJsonPath('data.title', 'Mr');
        $meta = (string) app(TenantContext::class)->runAs(null, fn () => DB::table('audit_logs')->where('action', 'learners.qcto_changed')->value('meta'), platform: true);
        $this->assertTrue(json_decode($meta, true)['by_learner']);
        $this->fresh()->getJson($this->url('/learn/home'), $this->bearer($this->learnerToken))->assertJsonPath('data.learner.qcto_missing', 6); // work status, address, province, Stats SA code, POPIA date, FLC
    }

    public function test_licences_show_seat_health(): void
    {
        $this->fresh()->getJson($this->url('/licences'), $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.0.seats_used', 1)->assertJsonPath('data.0.seats_not_started', 1)
            ->assertJsonPath('data.0.next_return_on', '2027-02-17')->assertJsonPath('data.0.seats_returned', 0);
        Carbon::setTestNow('2027-02-17 09:00:00');
        app(BillingRun::class)->run();
        $this->fresh()->getJson($this->url('/licences'), $this->bearer($this->admin))
            ->assertJsonPath('data.0.seats_used', 0)->assertJsonPath('data.0.seats_not_started', 0)->assertJsonPath('data.0.seats_returned', 1);
    }
}
