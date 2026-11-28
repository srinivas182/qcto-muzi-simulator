<?php

declare(strict_types=1);

namespace Tests\Feature\Workplace;

use App\Learners\IdNumbers;
use App\Models\AssessmentTask;
use App\Models\Centre;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\LogbookEntry;
use App\Models\Package;
use App\Models\ProgrammeVersion;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 5.3a: the workplace placement and the learner's daily logbook check-in. */
final class LogbookTest extends AccessTestCase
{
    private ProgrammeVersion $version;

    private Enrolment $enrolment;

    private string $learner;

    private string $admin;

    /** @var list<string> */
    private array $codes;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2027-03-10 16:00:00');
        Storage::fake('local');
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        $programme = (string) DB::table('programmes')->where('code', 'technical-support')->value('id');
        $this->version = ProgrammeVersion::query()->where('programme_id', $programme)->where('status', 'published')->firstOrFail();
        $this->codes = DB::table('outcomes')->where('programme_version_id', $this->version->id)->orderBy('position')->limit(3)->pluck('code')->all();
        $task = AssessmentTask::query()->create(['programme_version_id' => $this->version->id, 'code' => 'TS-W01', 'title' => 'Workplace logbook', 'component' => 'workplace', 'kind' => 'logbook', 'position' => 1, 'settings' => ['min_days' => 20], 'status' => 'published', 'content' => []]);
        DB::table('assessment_task_outcomes')->insert([['assessment_task_id' => $task->id, 'outcome_code' => $this->codes[0]], ['assessment_task_id' => $task->id, 'outcome_code' => $this->codes[1]]]);
        [$this->learner, $this->enrolment] = $this->enrolled();
        [, $this->admin] = $this->person($this->ubuntu, 'tp_admin');
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

    private function place(): TestResponse
    {
        return $this->fresh()->putJson($this->url("/enrolments/{$this->enrolment->id}/workplace"), [
            'employer' => 'Khanya IT Repairs', 'site' => 'Soweto', 'mentor_name' => 'Thabo Mokoena', 'mentor_phone' => '082 555 0101', 'confirm_by' => 'link', 'starts_on' => '2027-03-01',
        ], $this->bearer($this->admin));
    }

    private function checkIn(array $body = []): TestResponse
    {
        return $this->fresh()->postJson($this->url("/learn/enrolments/{$this->enrolment->id}/logbook"), $body + ['client_id' => (string) Str::uuid(), 'day' => '2027-03-10', 'hours' => 7.5, 'outcomes' => [$this->codes[0]], 'note' => 'Replaced a RAM module.'], $this->bearer($this->learner));
    }

    public function test_a_learner_logs_a_day_against_the_workplace_outcomes_safely_and_once(): void
    {
        $this->checkIn()->assertUnprocessable()->assertJsonValidationErrors('workplace'); // no placement yet
        $this->place()->assertOk()->assertJsonPath('data.mentor_phone', '+27825550101');
        $this->assertAudited('workplace.placed');

        $view = $this->fresh()->getJson($this->url("/learn/enrolments/{$this->enrolment->id}/logbook"), $this->bearer($this->learner))->assertOk();
        $view->assertJsonPath('data.workplace.employer', 'Khanya IT Repairs')->assertJsonMissingPath('data.workplace.mentor_phone')->assertJsonCount(2, 'data.outcomes')->assertJsonPath('data.summary.min_days', 20);

        $id = (string) Str::uuid();
        $first = $this->checkIn(['client_id' => $id, 'outcomes' => [$this->codes[0], $this->codes[2], 'NOPE']])->assertCreated()->assertJsonPath('data.outcomes', [$this->codes[0]]); // only the logbook's outcomes
        $this->checkIn(['client_id' => $id, 'hours' => 3])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.hours', 7.5); // resent from the phone: no change
        $this->assertAudited('workplace.checked_in');

        $this->checkIn(['day' => '2027-03-11'])->assertUnprocessable()->assertJsonValidationErrors('day');       // tomorrow
        $this->checkIn(['day' => '2027-02-20'])->assertUnprocessable()->assertJsonValidationErrors('day');       // before the placement and > 14 days
        $this->checkIn(['day' => '2027-03-09', 'hours' => 14])->assertUnprocessable()->assertJsonValidationErrors('hours');
        $this->checkIn(['day' => '2027-03-02'])->assertCreated(); // 8 days late: fine, no signal on site

        // A small photo; too big is refused.
        $entry = (string) $first->json('data.id');
        $photo = fn (UploadedFile $f) => $this->fresh()->post($this->url("/learn/logbook/{$entry}/photo"), ['photo' => $f], $this->bearer($this->learner) + ['Accept' => 'application/json']);
        $photo(UploadedFile::fake()->create('big.jpg', 300, 'image/jpeg'))->assertUnprocessable();
        $photo(UploadedFile::fake()->image('day.jpg', 640, 480)->size(90))->assertOk()->assertJsonPath('data.photo', true);
        $this->fresh()->get($this->url("/logbook/{$entry}/photo"), $this->bearer($this->learner))->assertOk();
        $this->fresh()->get($this->url("/logbook/{$entry}/photo"), $this->bearer($this->admin))->assertOk();
        [$other] = $this->enrolled();
        $this->fresh()->get($this->url("/logbook/{$entry}/photo"), $this->bearer($other) + ['Accept' => 'application/json'])->assertNotFound();

        // A confirmed day can't be changed.
        app(TenantContext::class)->runAs($this->ubuntu, fn () => LogbookEntry::query()->whereKey($entry)->update(['status' => 'confirmed']));
        $this->checkIn()->assertUnprocessable()->assertJsonValidationErrors('day');
        $staff = $this->fresh()->getJson($this->url("/enrolments/{$this->enrolment->id}/logbook"), $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.summary.days', 2)->assertJsonPath('data.summary.confirmed_days', 1)->assertJsonPath('data.workplace.mentor_phone', '+27825550101');
        $this->assertSame([$this->codes[0] => 1], $staff->json('data.summary.outcomes'));
    }

    public function test_only_staff_place_learners_and_other_providers_see_nothing(): void
    {
        $this->fresh()->putJson($this->url("/enrolments/{$this->enrolment->id}/workplace"), ['employer' => 'X', 'mentor_name' => 'Y', 'mentor_phone' => '0825550101', 'confirm_by' => 'link', 'starts_on' => '2027-03-01'], $this->bearer($this->learner))->assertForbidden();
        $this->fresh()->putJson($this->url("/enrolments/{$this->enrolment->id}/workplace"), ['employer' => 'X', 'mentor_name' => 'Y', 'mentor_phone' => '0825550101', 'confirm_by' => 'account', 'starts_on' => '2027-03-01'], $this->bearer($this->admin))
            ->assertUnprocessable()->assertJsonValidationErrors('mentor_user_id');
        $this->place()->assertOk();
        [, $lebone] = $this->person($this->lebone, 'tp_admin');
        $this->fresh()->getJson($this->url("/enrolments/{$this->enrolment->id}/logbook", 'lebone.simuwork.test'), $this->bearer($lebone))->assertNotFound();
    }
}
