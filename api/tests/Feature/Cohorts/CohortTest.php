<?php

declare(strict_types=1);

namespace Tests\Feature\Cohorts;

use App\Billing\BillingRun;
use App\Billing\SeatUsage;
use App\Learners\IdNumbers;
use App\Models\Centre;
use App\Models\Enrolment;
use App\Models\Invoice;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\Package;
use App\Models\Tenant;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 3.6a: cohorts, enrolments, seats and the seat rules. */
final class CohortTest extends AccessTestCase
{
    private string $admin;

    private string $programme;

    private string $centre;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2027-02-01 09:00:00');
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        [, $this->admin] = $this->person($this->ubuntu, 'tp_admin');
        $this->programme = (string) DB::table('programmes')->where('code', 'technical-support')->value('id');
        $this->centre = (string) $this->in($this->ubuntu, fn () => Centre::query()->create(['name' => 'Main campus'])->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function in(Tenant $tenant, callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $fn);
    }

    private function pool(int $seats, ?Tenant $tenant = null, string $status = 'active', ?string $endsOn = '2027-12-31'): LicencePool
    {
        $package = Package::query()->firstOrCreate(['code' => 'ts-test'], [
            'name' => 'TS', 'programme_id' => $this->programme, 'includes_simulators' => true, 'includes_courses' => true, 'billing' => 'term', 'term_months' => 12, 'min_seats' => 1,
        ]);

        return $this->in($tenant ?? $this->ubuntu, fn () => LicencePool::query()->forceCreate([
            'package_id' => $package->id, 'programme_id' => $this->programme, 'billing' => 'term', 'term_months' => 12, 'unit_price_cents' => 100000,
            'seats' => $seats, 'starts_on' => '2027-01-01', 'ends_on' => $endsOn, 'status' => $status,
        ]));
    }

    /** @return list<string> */
    private function learners(int $n, ?Tenant $tenant = null): array
    {
        return $this->in($tenant ?? $this->ubuntu, fn () => array_map(fn (int $i) => Learner::query()->create([
            'first_names' => "Learner{$i}", 'surname' => 'Test', 'id_type' => 'passport', 'phone' => '+278200000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'status' => 'invited',
        ] + IdNumbers::protect('passport', 'P'.str_pad((string) $i, 7, '0', STR_PAD_LEFT).bin2hex(random_bytes(2))))->id, range(1, $n)));
    }

    /** @param array<string, mixed> $over */
    private function cohort(array $over = [], ?string $token = null): TestResponse
    {
        return $this->fresh()->postJson($this->url('/cohorts'), $over + [
            'name' => 'TS February 2027', 'centre_id' => $this->centre, 'programme_id' => $this->programme, 'starts_on' => '2027-02-03',
        ], $this->bearer($token ?? $this->admin));
    }

    /** @param list<string> $ids */
    private function enrol(string $cohort, array $ids, ?string $token = null): TestResponse
    {
        return $this->fresh()->postJson($this->url("/cohorts/{$cohort}/enrolments"), ['learner_ids' => $ids], $this->bearer($token ?? $this->admin));
    }

    public function test_a_cohort_is_pinned_to_the_published_version_and_gets_a_join_code(): void
    {
        $this->pool(10);
        $res = $this->cohort()->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.qcto_type', 'qualification')
            ->assertJsonPath('data.free_seats', 10)->assertJsonPath('data.join_open', true);
        $this->assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{8}$/', (string) $res->json('data.join_code'));
        $this->assertAudited('cohorts.created', ['tenant_id' => $this->ubuntu->id]);
        $this->cohort(['ends_on' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
    }

    public function test_enrolling_takes_seats_and_refuses_when_there_are_not_enough(): void
    {
        $this->pool(3);
        $cohort = (string) $this->cohort()->json('data.id');
        [$a, $b, $c, $d] = $this->learners(4);

        $this->enrol($cohort, [$a, $b, $c, $d])->assertUnprocessable()
            ->assertJsonPath('errors.learners.0', 'Only 3 seats are free for this programme; you picked 4. Buy 1 more seats or pick fewer learners.');
        $this->enrol($cohort, [$a, $b])->assertOk()->assertJsonPath('data.enrolled', 2);
        $this->enrol($cohort, [$a, $c])->assertOk()->assertJsonPath('data.enrolled', 1)->assertJsonPath('data.skipped.0.reason', 'Already enrolled on this programme.');
        $this->fresh()->getJson($this->url("/cohorts/{$cohort}"), $this->bearer($this->admin))->assertOk()->assertJsonPath('data.learners', 3)->assertJsonPath('data.free_seats', 0);
        $this->assertSame(3, $this->in($this->ubuntu, fn () => SeatUsage::used(LicencePool::query()->firstOrFail())));
    }

    public function test_the_database_never_lets_a_pool_go_over_its_seats_even_when_two_admins_race(): void
    {
        // Two requests that both counted one free seat would both try to insert. The trigger locks the pool row
        // and counts again, so the second insert fails even though the application check was passed.
        $pool = $this->pool(1);
        $cohort = (string) $this->cohort()->json('data.id');
        [$a, $b] = $this->learners(2);
        $this->in($this->ubuntu, function () use ($pool, $cohort, $a, $b): void {
            $row = fn (string $learner) => ['learner_id' => $learner, 'cohort_id' => $cohort, 'programme_id' => $this->programme,
                'programme_version_id' => DB::table('cohorts')->where('id', $cohort)->value('programme_version_id'), 'licence_pool_id' => $pool->id, 'seat_taken_at' => now()];
            Enrolment::query()->create($row($a));
            try {
                DB::transaction(fn () => Enrolment::query()->create($row($b)));
                $this->fail('The second seat must be refused.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('has no free seats', $e->getMessage());
            }
        });
    }

    public function test_expired_pools_and_other_providers_pools_give_no_seats(): void
    {
        $this->pool(5, null, 'active', '2027-01-31'); // ended yesterday
        $this->pool(5, $this->lebone);
        $cohort = (string) $this->cohort()->json('data.id');
        $this->enrol($cohort, $this->learners(1))->assertUnprocessable()
            ->assertJsonPath('errors.learners.0', 'You have no active licence for this programme. Buy seats in Licences & packages first.');
    }

    public function test_an_overdue_account_blocks_new_enrolments_but_not_learning(): void
    {
        $this->pool(5);
        $cohort = (string) $this->cohort()->json('data.id');
        $this->in($this->ubuntu, fn () => DB::table('invoices')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->ubuntu->id, 'number' => 'INV-999999', 'kind' => 'order', 'issue_date' => '2026-12-01', 'due_date' => '2026-12-15',
            'seller' => '{}', 'buyer' => '{}', 'lines' => '[]', 'subtotal_cents' => 100, 'vat_rate' => 15, 'vat_cents' => 15, 'total_cents' => 115, 'status' => Invoice::ISSUED, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->enrol($cohort, $this->learners(1))->assertStatus(423);
    }

    public function test_seat_rules_no_show_returns_after_14_days_and_early_withdrawal_returns_the_seat(): void
    {
        $this->pool(3);
        $cohort = (string) $this->cohort()->json('data.id'); // starts 3 Feb
        [$noShow, $starter, $early, $late] = $this->learners(4);
        $this->enrol($cohort, [$noShow, $starter, $early])->assertOk();
        $this->in($this->ubuntu, fn () => Enrolment::query()->where('learner_id', $starter)->update(['started_at' => now(), 'status' => 'active', 'progress' => 40]));

        // Early withdrawal (progress < 10%) gives the seat back; the learner's record stays.
        $early = (string) $this->in($this->ubuntu, fn () => Enrolment::query()->where('learner_id', $early)->value('id'));
        $this->fresh()->postJson($this->url("/enrolments/{$early}/withdraw"), ['reason' => 'Found a job'], $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.status', 'withdrawn')->assertJsonPath('data.holds_seat', false);
        $this->enrol($cohort, [$late])->assertOk()->assertJsonPath('data.enrolled', 1);
        $this->in($this->ubuntu, fn () => Enrolment::query()->where('learner_id', $late)->update(['started_at' => now(), 'status' => 'active']));

        // 14 days after the cohort start (3 Feb), the learner who never started gives their seat back.
        Carbon::setTestNow('2027-02-16 09:00:00');
        app(BillingRun::class)->run();
        $this->assertSame('enrolled', $this->in($this->ubuntu, fn () => Enrolment::query()->where('learner_id', $noShow)->value('status')));
        Carbon::setTestNow('2027-02-17 09:00:00');
        $this->assertSame(1, app(BillingRun::class)->run()['no_shows']);
        $this->assertSame(['no_show', false], $this->in($this->ubuntu, fn () => array_values(Enrolment::query()->where('learner_id', $noShow)->first(['status', 'holds_seat'])->toArray())));

        // A learner past 10% keeps the seat used when withdrawn.
        $started = (string) $this->in($this->ubuntu, fn () => Enrolment::query()->where('learner_id', $starter)->value('id'));
        $this->fresh()->postJson($this->url("/enrolments/{$started}/withdraw"), ['reason' => 'Moved away'], $this->bearer($this->admin))->assertJsonPath('data.holds_seat', true);
        $this->assertSame(2, $this->in($this->ubuntu, fn () => SeatUsage::used(LicencePool::query()->firstOrFail())));
    }

    public function test_transfer_keeps_the_seat_and_follows_the_new_cohort(): void
    {
        $this->pool(2);
        $one = (string) $this->cohort()->json('data.id');
        $two = (string) $this->cohort(['name' => 'TS evening class'])->json('data.id');
        [$a] = $this->learners(1);
        $this->enrol($one, [$a])->assertOk();
        $e = (string) $this->in($this->ubuntu, fn () => Enrolment::query()->value('id'));
        $this->fresh()->postJson($this->url("/enrolments/{$e}/transfer"), ['cohort_id' => $two], $this->bearer($this->admin))->assertOk()->assertJsonPath('data.holds_seat', true);
        $this->fresh()->getJson($this->url("/cohorts/{$two}"), $this->bearer($this->admin))->assertJsonPath('data.learners', 1)->assertJsonPath('data.free_seats', 1);
        $this->fresh()->postJson($this->url("/cohorts/{$one}/archive"), [], $this->bearer($this->admin))->assertOk()->assertJsonPath('data.archived', true);
        $this->fresh()->postJson($this->url("/cohorts/{$two}/archive"), [], $this->bearer($this->admin))->assertUnprocessable();
    }

    public function test_a_registered_learner_joins_with_the_qr_code_and_strangers_cannot(): void
    {
        $this->pool(2);
        $res = $this->cohort();
        $code = (string) $res->json('data.join_code');
        [$learnerUser, $learnerToken] = $this->person($this->ubuntu, 'learner');
        [$id] = $this->learners(1);
        $this->in($this->ubuntu, fn () => Learner::query()->whereKey($id)->update(['user_id' => $learnerUser->id, 'status' => 'active']));

        $this->fresh()->postJson($this->url('/cohorts/join'), ['code' => strtolower($code)], $this->bearer($learnerToken))->assertOk()->assertJsonPath('data.cohort', 'TS February 2027');
        $this->fresh()->postJson($this->url('/cohorts/join'), ['code' => $code], $this->bearer($learnerToken))->assertOk(); // scanning twice is fine
        $this->assertSame(1, $this->in($this->ubuntu, fn () => Enrolment::query()->count()));

        [, $stranger] = $this->person($this->ubuntu, 'learner');
        $this->fresh()->postJson($this->url('/cohorts/join'), ['code' => $code], $this->bearer($stranger))->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'Your provider has not added you as a learner yet. Ask your facilitator.');
        [, $lebone] = $this->person($this->lebone, 'learner');
        $this->fresh()->postJson($this->url('/cohorts/join', 'lebone.simuwork.test'), ['code' => $code], $this->bearer($lebone))->assertUnprocessable();
    }

    public function test_facilitators_see_cohorts_but_cannot_enrol_and_other_providers_see_nothing(): void
    {
        $this->pool(2);
        [$facUser, $facilitator] = $this->person($this->ubuntu, 'facilitator');
        $cohort = (string) $this->cohort(['facilitator_ids' => [$facUser->id]])->assertJsonPath('data.facilitators.0.id', $facUser->id)->json('data.id');
        $this->fresh()->getJson($this->url('/cohorts'), $this->bearer($facilitator))->assertOk()->assertJsonCount(1, 'data');
        $this->enrol($cohort, $this->learners(1), $facilitator)->assertForbidden();

        [, $lebone] = $this->person($this->lebone, 'tp_admin');
        $this->fresh()->getJson($this->url("/cohorts/{$cohort}", 'lebone.simuwork.test'), $this->bearer($lebone))->assertNotFound();
        [$learner] = $this->person($this->ubuntu, 'learner');
        $this->cohort(['facilitator_ids' => [$learner->id]])->assertUnprocessable()->assertJsonValidationErrors('facilitator_ids');
    }
}
