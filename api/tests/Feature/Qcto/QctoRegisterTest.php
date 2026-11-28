<?php

declare(strict_types=1);

namespace Tests\Feature\Qcto;

use App\Billing\BillingRun;
use App\Learners\IdNumbers;
use App\Models\Centre;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\Package;
use App\Models\Tenant;
use App\Notifications\QctoDeadlineReminder;
use App\Programmes\GuideImporter;
use App\Qcto\QctoRegister;
use App\Qcto\WorkingDays;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Access\AccessTestCase;
use ZipArchive;

/** Sprint 3.6b: QCTO details, the enrolment file (columns A–AQ), the 21-working-day deadline and reminders. */
final class QctoRegisterTest extends AccessTestCase
{
    private const ID = '8001015009087';

    private string $admin;

    private Learner $learner;

    private Cohort $cohort;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2027-03-01 09:00:00');
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        [, $this->admin] = $this->person($this->ubuntu, 'tp_admin');
        $this->ubuntu->update(['qcto_sdp_code' => 'SDP-0001', 'legal_name' => 'Ubuntu Skills Academy (Pty) Ltd']);
        $programme = (string) DB::table('programmes')->where('code', 'technical-support')->value('id');
        $package = Package::query()->create(['code' => 'ts', 'name' => 'TS', 'programme_id' => $programme, 'includes_simulators' => true, 'includes_courses' => true, 'billing' => 'term', 'term_months' => 12, 'min_seats' => 1]);
        app(TenantContext::class)->runAs($this->ubuntu, function () use ($programme, $package): void {
            $pool = LicencePool::query()->forceCreate(['package_id' => $package->id, 'programme_id' => $programme, 'billing' => 'term', 'term_months' => 12, 'unit_price_cents' => 1, 'seats' => 5, 'starts_on' => '2027-01-01', 'ends_on' => '2027-12-31', 'status' => 'active']);
            $version = DB::table('programme_versions')->where('programme_id', $programme)->where('status', 'published')->value('id');
            $this->cohort = Cohort::query()->create(['centre_id' => Centre::query()->create(['name' => 'Main'])->id, 'programme_id' => $programme, 'programme_version_id' => $version,
                'name' => 'TS March', 'starts_on' => '2027-03-15', 'ends_on' => '2028-02-28', 'join_code' => 'TESTCODE', 'qcto_qualification_id' => '118707']);
            $this->learner = Learner::query()->create(['first_names' => 'Sipho', 'surname' => 'Ndlovu', 'id_type' => 'sa_id', 'date_of_birth' => '1980-01-01', 'gender' => 'male',
                'citizenship' => 'sa', 'home_language' => 'zu', 'phone' => '+27825550101', 'status' => 'active'] + IdNumbers::protect('sa_id', self::ID));
            Enrolment::query()->create(['learner_id' => $this->learner->id, 'cohort_id' => $this->cohort->id, 'programme_id' => $programme, 'programme_version_id' => $version, 'licence_pool_id' => $pool->id, 'seat_taken_at' => now(), 'started_at' => now(), 'status' => 'active']);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function complete(): array
    {
        return [
            'title' => 'Mr', 'equity' => 'BA', 'socioeconomic_code' => '02', 'disability_status' => 'N', 'province_code' => '7', 'stats_area_code' => '79800',
            'home_address' => ['line1' => '12 Vilakazi Street', 'line2' => 'Orlando West', 'line3' => 'Soweto', 'postal_code' => '0181'], 'flc_code' => '03', 'flc_sor_number' => 'NSC12345',
        ];
    }

    public function test_the_deadline_is_21_working_days_and_skips_weekends_and_public_holidays(): void
    {
        // 15 Mar 2027 start: 22 Mar (Human Rights Day moved from Sunday), 26 Mar (Good Friday), 29 Mar (Family Day) do not count.
        $this->assertSame('2027-04-16', WorkingDays::add(Carbon::parse('2027-03-15'), 21)->toDateString());
        $this->assertSame('2027-04-16', QctoRegister::dueOn($this->cohort)?->toDateString());
        $this->fresh()->getJson($this->url("/cohorts/{$this->cohort->id}"), $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.qcto_due_on', '2027-04-16')->assertJsonPath('data.qcto_qualification_id', '118707');
    }

    public function test_qcto_details_are_validated_equity_and_disability_are_encrypted_and_never_audited(): void
    {
        $url = $this->url("/learners/{$this->learner->id}/qcto");
        $this->fresh()->getJson($url, $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.citizen_status', 'SA')->assertJsonPath('data.immigrant_status', '03')->assertJsonPath('data.nationality_code', 'SA');
        $this->fresh()->patchJson($url, ['disability_status' => '04', 'home_address' => ['line1' => '12', 'line2' => 'Soweto', 'postal_code' => '181']], $this->bearer($this->admin))
            ->assertUnprocessable()->assertJsonValidationErrors(['disability_rating', 'home_address']);
        $this->fresh()->patchJson($url, $this->complete(), $this->bearer($this->admin))->assertOk()->assertJsonPath('data.equity', 'BA')->assertJsonPath('data.missing', ['popi']);

        $raw = (array) app(TenantContext::class)->runAs($this->ubuntu, fn () => DB::table('learners')->where('id', $this->learner->id)->first(['equity_encrypted', 'disability_encrypted']));
        $this->assertNotSame('BA', $raw['equity_encrypted']);
        $this->assertStringNotContainsString('"N"', (string) $raw['disability_encrypted']);
        $log = app(TenantContext::class)->runAs(null, fn () => DB::table('audit_logs')->where('action', 'learners.qcto_changed')->value('meta'), platform: true);
        $this->assertStringContainsString('equity', (string) $log);
        $this->assertStringNotContainsString('BA', (string) $log);

        // A facilitator may look at the learner but not at equity or disability.
        [, $facilitator] = $this->person($this->ubuntu, 'facilitator');
        $this->fresh()->getJson($url, $this->bearer($facilitator))->assertOk()->assertJsonPath('data.equity', null)->assertJsonPath('data.disability_status', null);
        $this->fresh()->patchJson($url, ['title' => 'Dr'], $this->bearer($facilitator))->assertForbidden();
    }

    public function test_the_check_lists_what_is_missing_before_download(): void
    {
        $this->ubuntu->update(['qcto_sdp_code' => null, 'accreditation_number' => null]);
        $this->fresh()->getJson($this->url("/cohorts/{$this->cohort->id}/qcto-check"), $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.cohort', ['sdp_code'])->assertJsonPath('data.complete', 0)->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.learners.0.missing', ['title', 'equity', 'socioeconomic_code', 'disability_status', 'home_address', 'province_code', 'stats_area_code', 'popi', 'flc_code']);
    }

    public function test_the_enrolment_file_has_qctos_43_text_columns_and_needs_the_reveal_permission(): void
    {
        $this->fresh()->patchJson($this->url("/learners/{$this->learner->id}/qcto"), $this->complete(), $this->bearer($this->admin))->assertOk();
        app(TenantContext::class)->runAs($this->ubuntu, fn () => Learner::query()->whereKey($this->learner->id)->update(['popi_agreed_at' => '2027-02-20 10:00:00']));

        [, $manager] = $this->person($this->ubuntu, 'centre_manager');
        $this->fresh()->get($this->url("/cohorts/{$this->cohort->id}/qcto-register"), $this->bearer($manager))->assertForbidden();

        $res = $this->fresh()->get($this->url("/cohorts/{$this->cohort->id}/qcto-register"), $this->bearer($this->admin))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="LEISA20270301-Ubuntu Skills Academy (Pty) Ltd.xlsx"');
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents((string) $path, $res->getContent());
        $zip = new ZipArchive;
        $zip->open((string) $path);
        $xml = simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml'));
        $rows = [];
        foreach ($xml->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $this->assertSame('inlineStr', (string) $c['t']); // every value is text
                $cells[preg_replace('/\d+$/', '', (string) $c['r'])] = (string) $c->is->t;
            }
            $rows[] = $cells;
        }
        $this->assertCount(43, $rows[0]);
        $this->assertSame('Date Stamp', $rows[0]['AQ']);
        $this->assertSame([
            'A' => 'SDP-0001', 'B' => '118707', 'C' => self::ID, 'E' => '533', 'F' => 'BA', 'G' => 'SA', 'H' => 'Zul', 'I' => 'M', 'J' => 'SA', 'K' => '02', 'L' => 'N', 'N' => '03',
            'O' => 'Ndlovu', 'P' => 'Sipho', 'R' => 'Mr', 'S' => '19800101', 'T' => '12 Vilakazi Street', 'U' => 'Orlando West', 'V' => 'Soweto',
            'W' => '12 Vilakazi Street', 'X' => 'Orlando West', 'Y' => 'Soweto', 'Z' => '0181', 'AA' => '0181', 'AC' => '0825550101',
            'AF' => '7', 'AG' => '79800', 'AH' => 'Yes', 'AI' => '20270220', 'AJ' => '20280228', 'AK' => '02', 'AN' => '4', 'AO' => '03', 'AP' => 'NSC12345', 'AQ' => '20270301',
        ], $rows[1]);
        $this->assertAudited('cohorts.qcto_register_downloaded', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_admins_are_reminded_5_working_days_before_and_when_late_until_submitted(): void
    {
        Carbon::setTestNow('2027-04-08 09:00:00'); // 6 working days before 16 April: nothing yet
        $this->assertSame(0, app(BillingRun::class)->run()['qcto_reminders']);
        Carbon::setTestNow('2027-04-09 09:00:00'); // 5 working days before
        $this->assertSame(1, app(BillingRun::class)->run()['qcto_reminders']);
        $this->assertSame(0, app(BillingRun::class)->run()['qcto_reminders']); // once per level
        Carbon::setTestNow('2027-04-16 09:00:00');
        $this->assertSame(1, app(BillingRun::class)->run()['qcto_reminders']);
        Notification::assertSentTimes(QctoDeadlineReminder::class, 2);

        $this->fresh()->patchJson($this->url("/cohorts/{$this->cohort->id}"), ['qcto_submitted' => true], $this->bearer($this->admin))->assertOk();
        app(TenantContext::class)->runAs($this->ubuntu, fn () => Cohort::query()->whereKey($this->cohort->id)->update(['qcto_reminder_level' => 0]));
        $this->assertSame(0, app(BillingRun::class)->run()['qcto_reminders']);
    }

    public function test_providers_set_their_qcto_sdp_code(): void
    {
        $this->fresh()->patchJson($this->url('/academy'), ['qcto_sdp_code' => 'SDP/123-45'], $this->bearer($this->admin))->assertOk()->assertJsonPath('data.qcto_sdp_code', 'SDP/123-45');
        $this->assertSame('SDP/123-45', Tenant::query()->findOrFail($this->ubuntu->id)->qcto_sdp_code);
    }
}
