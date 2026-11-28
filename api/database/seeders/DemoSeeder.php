<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\LicenceOrders;
use App\Billing\Payments;
use App\Cohorts\Cohorts;
use App\Cohorts\Enrolments;
use App\Identity\ConsentService;
use App\Learners\IdNumbers;
use App\Learners\LearnerDirectory;
use App\Learning\ActivityCatalogue;
use App\Learning\LessonContent;
use App\Learning\ScenarioContent;
use App\Models\Activity;
use App\Models\BillingSetting;
use App\Models\Centre;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\OutcomeCoverage;
use App\Models\Package;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Programmes\GuideImporter;
use App\Tenancy\DomainService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Demo data for local development and prospect demos (never production):
 *   php artisan db:seed --class=DemoSeeder
 * One provider (ubuntu.<platform domain>) with a person for each role, and HQ staff.
 * Every account's password is "demo-password-2026".
 */
final class DemoSeeder extends Seeder
{
    public const PASSWORD = 'demo-password-2026';

    public function run(TenantContext $context, DomainService $domains, ConsentService $consents): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $context->runAs(null, function () use ($context, $domains, $consents): void {
            $tenant = Tenant::query()->firstOrCreate(['slug' => 'ubuntu'], ['name' => 'Ubuntu Skills Academy', 'status' => 'active']);
            $tenant->forceFill([
                'legal_name' => 'Ubuntu Skills Academy (Pty) Ltd', 'registration_number' => '2019/482113/07', 'vat_number' => '4710283456',
                'accreditation_number' => 'SDP0712/24-00321', 'accreditation_expires_at' => now()->addDays(45)->toDateString(),
                'contact_email' => 'hello@ubuntu.demo', 'contact_phone' => '+27 31 555 0142', 'billing_email' => 'accounts@ubuntu.demo',
                'address' => ['line1' => '14 Anton Lembede St', 'city' => 'Durban', 'province' => 'KwaZulu-Natal', 'postal_code' => '4001'],
            ])->save();
            if (! $tenant->domains()->exists()) {
                $domains->createSubdomain($tenant);
            }

            $people = [
                'tp_admin' => ['Nomsa Dlamini', 'admin@ubuntu.demo'],
                'centre_manager' => ['Pieter van Wyk', 'centre@ubuntu.demo'],
                'facilitator' => ['Lerato Mokoena', 'facilitator@ubuntu.demo'],
                'assessor' => ['Sibusiso Nkosi', 'assessor@ubuntu.demo'],
                'moderator' => ['Ayesha Patel', 'moderator@ubuntu.demo'],
                'learner' => ['Thandiwe Khumalo', 'learner@ubuntu.demo'],
                'qcto_reviewer' => ['Johan Botha', 'qcto@ubuntu.demo'],
            ];

            $context->runAs($tenant, function () use ($tenant, $people, $consents): void {
                foreach ($people as $roleKey => [$name, $email]) {
                    $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => self::PASSWORD, 'email_verified_at' => now()]);
                    $role = Role::query()->where('key', $roleKey)->firstOrFail();
                    $user->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => $tenant->id]]);
                    foreach (array_keys($consents->current()) as $purpose) {
                        $consents->record($user, $purpose, true);
                    }
                }
            });

            $hq = [
                'super_admin' => ['Srini (HQ)', 'srini@hq.demo'],
                'support_finance' => ['Thabo Molefe', 'support@hq.demo'],
                'content_manager' => ['Zanele Mthembu', 'content@hq.demo'],
            ];
            foreach ($hq as $roleKey => [$name, $email]) {
                $user = User::query()->whereNull('tenant_id')->firstOrCreate(['email' => $email, 'tenant_id' => null], ['name' => $name, 'password' => self::PASSWORD, 'email_verified_at' => now()]);
                $role = Role::query()->whereNull('tenant_id')->where('key', $roleKey)->firstOrFail();
                $user->roles()->syncWithoutDetaching([$role->id => ['tenant_id' => null]]);
            }
        }, platform: true);

        app(GuideImporter::class)->import(null, publish: true);
        $this->demoCoverage();
        $this->demoPackages();
        $this->demoBilling($context);
        $this->demoActivities($context);
        $this->demoLearners($context);
        $this->demoCohort($context);

        $this->command?->info('Demo ready. Password for every account: '.self::PASSWORD);
    }

    /** Placeholder seller details, a paid 60-seat licence and an unpaid monthly order for Ubuntu. */
    private function demoBilling(TenantContext $context): void
    {
        BillingSetting::current()->update([
            'seller_name' => 'SimuWork Demo (Pty) Ltd', 'registration_number' => '2026/000000/07', 'vat_number' => '4000000000',
            'address' => ['line1' => '1 Demo Street', 'city' => 'Johannesburg', 'province' => 'Gauteng', 'postal_code' => '2001'],
            'email' => 'accounts@simuwork.demo', 'bank' => ['bank' => 'Demo Bank', 'account_name' => 'SimuWork Demo (Pty) Ltd', 'account_number' => '1234567890', 'branch_code' => '250655', 'account_type' => 'Cheque'],
        ]);
        $tenant = Tenant::query()->where('slug', 'ubuntu')->firstOrFail();
        if ($context->runAs($tenant, fn () => LicencePool::query()->exists())) {
            return;
        }
        $hq = $context->runAs(null, fn () => User::query()->whereNull('tenant_id')->where('email', 'srini@hq.demo')->firstOrFail(), platform: true);
        $admin = $context->runAs($tenant, fn () => User::query()->where('email', 'admin@ubuntu.demo')->firstOrFail());
        $context->runAs($tenant, function () use ($tenant, $admin, $hq): void {
            $orders = app(LicenceOrders::class);
            $paid = $orders->buy($tenant, Package::query()->where('code', 'technical-support-12-months')->firstOrFail(), 60, $admin);
            app(Payments::class)->recordEft($paid['invoice'], $paid['invoice']->total_cents, 'DEMO EFT 0001', today(), $hq);
            $orders->buy($tenant, Package::query()->where('code', 'data-analytics-courses-monthly')->firstOrFail(), 10, $admin);
        });
    }

    /** A small class at Ubuntu: some invited, one joined, one passport holder. Test ID numbers only. */
    private function demoLearners(TenantContext $context): void
    {
        $tenant = Tenant::query()->where('slug', 'ubuntu')->firstOrFail();
        $context->runAs($tenant, function () use ($tenant): void {
            if (Learner::query()->exists()) {
                return;
            }
            $admin = User::query()->where('email', 'admin@ubuntu.demo')->firstOrFail();
            $rows = [
                ['Sipho', 'Ndlovu', '0003075001081', '082 555 0101', 'zu'], ['Thandi', 'Mokoena', '0105120123087', '082 555 0102', 'st'],
                ['Lerato', 'Dlamini', '9911250456088', '082 555 0103', 'zu'], ['Ayanda', 'Khumalo', '0207155789080', '082 555 0104', 'xh'],
                ['Pieter', 'van Wyk', '9808085123083', '082 555 0105', 'af'], ['Naledi', 'Mahlangu', '0112290034089', '082 555 0106', 'nr'],
                ['Kagiso', 'Molefe', '0009015432084', '082 555 0107', 'tn'], ['Zanele', 'Sithole', '0204040876086', '082 555 0108', 'zu'],
            ];
            $import = array_map(fn (array $r) => ['first_names' => $r[0], 'surname' => $r[1], 'id_number' => self::luhn($r[2]), 'phone' => $r[3], 'home_language' => $r[4]], $rows);
            $import[] = ['first_names' => 'Grace', 'surname' => 'Banda', 'id_type' => 'passport', 'id_number' => 'MW1234567', 'phone' => '082 555 0109', 'home_language' => 'en', 'date_of_birth' => '2001-06-14'];
            $directory = app(LearnerDirectory::class);
            $directory->import($tenant, $import, false, false, $admin);
            Learner::query()->where('surname', 'Ndlovu')->update(['status' => Learner::ACTIVE, 'joined_at' => now()->subDays(3)]);
        });
    }

    /** A Technical Support cohort at Ubuntu with six learners from the register (seats from the paid licence). */
    /**
     * The first Technical Support activities (titles and outcome links; lesson and scenario content arrive
     * with Course Studio and Scenario Studio). Published, so the coverage map shows them as linked.
     */
    /** Demo lesson content (English, reviewed by HQ's content manager). Translations come through Course Studio. */
    private const DEMO_LESSONS = [
        'TS-L01' => [
            ['type' => 'narration', 'script' => 'The phone rings: a user’s printer has stopped working. Before you fix anything, you log it. A good ticket saves everyone time.'],
            ['type' => 'keypoints', 'items' => ['Who: name, department and contact number', 'What: the problem in the user’s own words', 'When: since when, and how often', 'Impact: how many people are stuck']],
            ['type' => 'text', 'body' => 'Then set the **priority**. A whole team that cannot work is more urgent than one slow screen. Your service level agreement (SLA) says how fast each priority must be handled.'],
            ['type' => 'check', 'prompt' => 'Five people in Finance cannot print payslips due today. One person’s mouse is sticky. Which ticket comes first?', 'options' => ['The sticky mouse, because it was logged first', 'Finance printing, because more people are stuck and there is a deadline', 'Whichever is quicker to fix'], 'answer' => 1, 'explain' => 'Impact and urgency set the priority, not the order calls came in.', 'outcome' => 'TS-1.2'],
        ],
        'TS-L02' => [
            ['type' => 'narration', 'script' => 'Inside a PC there is mains power, and parts that a tiny spark of static can kill. Two habits keep you and the PC safe.'],
            ['type' => 'keypoints', 'items' => ['Shut down, switch off at the wall and unplug', 'Hold the power button for 5 seconds to drain left-over power', 'Wear an antistatic wrist strap clipped to the bare metal of the case', 'Hold memory modules by the edges, never the gold pins']],
            ['type' => 'text', 'body' => 'Static from your body can be thousands of volts. You will not feel it, but a memory module can be damaged and fail weeks later. That is why the strap matters even when nothing seems to happen.'],
            ['type' => 'check', 'prompt' => 'You must reseat a RAM module. What do you do first?', 'options' => ['Open the case and pull the module out', 'Shut down, switch off and unplug the PC', 'Put on the strap and start working with the PC on'], 'answer' => 1, 'explain' => 'Power off and unplugged comes first, every time. Then the strap.', 'outcome' => 'TS-2.2'],
            ['type' => 'check', 'prompt' => 'Where do you clip the antistatic strap?', 'options' => ['To a painted part of the case', 'To bare metal on the case', 'To the RAM module'], 'answer' => 1, 'explain' => 'Paint does not conduct. Bare metal lets the static drain safely.', 'outcome' => 'TS-2.2'],
            ['type' => 'try', 'activity_code' => 'TS-S01'],
        ],
        'TS-L03' => [
            ['type' => 'narration', 'script' => 'The PC works again. Now tell the user what happened, in words they understand.'],
            ['type' => 'keypoints', 'items' => ['Say what was wrong, without jargon', 'Say what you did', 'Ask them to try it while you are there', 'Close the ticket with the same words']],
            ['type' => 'check', 'prompt' => 'Which explanation is best for a non-technical user?', 'options' => ['“The DIMM in slot A2 had a contact fault, so POST failed.”', '“One of the memory parts was loose, so the PC could not start. I put it back firmly and tested it. Can you log in for me?”', '“It is fixed now.”'], 'answer' => 1, 'explain' => 'Plain words, what you did, and a check that it works for them.', 'outcome' => 'TS-6.1'],
        ],
    ];

    /** The first simulator: "A PC won't boot" on the PC bench scene (Sprint 4.4; Scenario Studio edits it from 4.5). */
    private const DEMO_SCENARIO = [
        'scene' => 'pc-bench', 'pass_mark' => 70,
        'job' => ['minutes' => 12, 'customer' => 'Thandi from accounts needs her PC back before payroll at 14:00.'],
        // Two causes of the same complaint: each run picks one, so learners diagnose instead of memorising.
        'variants' => [['id' => 'ram', 'label' => 'Faulty RAM (beeps)', 'start' => []], ['id' => 'cable', 'label' => 'Loose power cable (dead)', 'start' => ['psu-cable' => 'loose']]],
        'steps' => [
            ['id' => 'press', 'type' => 'tap', 'target' => 'pc-power', 'only' => ['ram'], 'prompt' => 'Thandi says her PC will not start. Press its power button and watch what happens.', 'sets' => ['monitor' => 'beeps'],
                'hints' => ['Look at the front of the PC.', 'The round button on the front of the case.'], 'why' => 'See the fault for yourself before you change anything.', 'outcome' => 'TS-2.2',
                'wrong' => [['on' => '*', 'effect' => 'none', 'message' => 'First see the fault for yourself: try to switch the PC on.']]],
            ['id' => 'pressc', 'type' => 'tap', 'target' => 'pc-power', 'only' => ['cable'], 'prompt' => 'Thandi says her PC will not start. Press its power button and watch what happens.', 'sets' => ['pc-power' => 'dead'],
                'hints' => ['Look at the front of the PC.', 'The round button on the front of the case.'], 'why' => 'See the fault for yourself before you change anything.', 'outcome' => 'TS-2.2',
                'wrong' => [['on' => '*', 'effect' => 'none', 'message' => 'First see the fault for yourself: try to switch the PC on.']]],
            ['id' => 'diagc', 'type' => 'choose', 'only' => ['cable'], 'prompt' => 'Nothing at all: no lights, no fans, no beeps. What do you check first?', 'options' => ['The RAM', 'The power cable at the back of the PC', 'Reinstall Windows'], 'answer' => 1,
                'hints' => ['No beeps means the PC is not getting power at all.', 'Start with the simplest thing: is it plugged in properly?'], 'why' => 'Check the simple things first. Many "dead" PCs just have a loose cable.', 'outcome' => 'TS-2.2', 'lesson' => ['code' => 'TS-L01'],
                'wrong' => [['on' => 'option:0', 'effect' => 'none', 'message' => 'Faulty RAM still lets the fans spin and makes the PC beep. This one is completely dead.'], ['on' => 'option:2', 'effect' => 'customer', 'message' => 'Thandi sighs: reinstalling takes hours, and Windows never even started.']]],
            ['id' => 'diagnose', 'type' => 'choose', 'only' => ['ram'], 'prompt' => 'Three long beeps and a blank screen. What is the most likely cause?', 'options' => ['The power supply is dead', 'A memory (RAM) problem', 'A virus'], 'answer' => 1,
                'hints' => ['The PC has power: the fans spin and it beeps.', 'Long repeated beeps at start-up usually point to memory.'], 'why' => 'Beep codes tell you where to look, so you do not replace parts that are fine.', 'outcome' => 'TS-2.2', 'lesson' => ['code' => 'TS-L01'],
                'wrong' => [['on' => 'option:0', 'effect' => 'none', 'message' => 'A dead power supply gives no beeps and no fans. This PC beeps.'], ['on' => 'option:2', 'effect' => 'none', 'message' => 'A virus cannot stop the PC beeping before Windows starts.']]],
            ['id' => 'unplug', 'type' => 'tap', 'target' => 'wall-switch', 'critical' => true, 'prompt' => 'Make it safe before you work on the PC.', 'sets' => ['wall-switch' => 'off', 'monitor' => 'off'],
                'hints' => ['Power must be off at the source.', 'Switch off at the wall socket.'], 'why' => 'Opening a live PC can shock you and destroy parts.', 'outcome' => 'TS-3.2', 'lesson' => ['code' => 'TS-L02'],
                'wrong' => [['on' => 'case-panel', 'effect' => 'spark', 'message' => 'You opened a live PC. In a real job this can shock you and damage the motherboard.']]],
            ['id' => 'strap', 'only' => ['ram'], 'type' => 'tap', 'target' => 'strap', 'critical' => true, 'prompt' => 'Protect the parts from static.', 'sets' => ['strap' => 'on'],
                'hints' => ['Something on the bench stops static from your body.', 'Put on the antistatic wrist strap.'], 'why' => 'Static from your hand can kill a RAM stick without a visible mark.', 'outcome' => 'TS-3.2', 'lesson' => ['code' => 'TS-L02'],
                'wrong' => [['on' => 'ram-new', 'effect' => 'static', 'message' => 'You touched the RAM without a strap. Static can damage it without you seeing anything.'], ['on' => 'case-panel', 'effect' => 'static', 'message' => 'Put the strap on before you reach inside the case.']]],
            ['id' => 'open', 'only' => ['ram'], 'type' => 'tap', 'target' => 'case-panel', 'prompt' => 'Open the side of the case.', 'sets' => ['case-panel' => 'open'], 'hints' => ['The flat panel on the side of the PC.'], 'why' => 'You need to see the memory slots.', 'outcome' => 'TS-3.2'],
            ['id' => 'remove', 'only' => ['ram'], 'type' => 'tap', 'target' => 'ram-old', 'prompt' => 'Take out the faulty RAM stick.', 'sets' => ['ram-old' => 'out', 'slot-a1' => 'empty'], 'hints' => ['It is in the slot next to the processor.', 'Press the clips at both ends, then lift the green stick.'], 'why' => 'Release the clips first so the slot is not damaged.', 'outcome' => 'TS-3.2'],
            ['id' => 'insert', 'only' => ['ram'], 'type' => 'place', 'target' => 'ram-new', 'slot' => 'slot-a1', 'prompt' => 'Fit the new RAM stick into the empty slot.', 'sets' => ['ram-new' => 'in', 'slot-a1' => 'full'],
                'hints' => ['Pick up the new stick from the bench.', 'Tap the new stick, then tap the empty slot A1.'], 'why' => 'Line up the notch and press until both clips click, or the PC will still not boot.', 'outcome' => 'TS-3.2',
                'wrong' => [['on' => 'ram-old', 'effect' => 'beep', 'message' => 'That is the faulty stick. Use the new one from the bench.']]],
            ['id' => 'close', 'only' => ['ram'], 'type' => 'tap', 'target' => 'case-panel', 'prompt' => 'Close the case.', 'sets' => ['case-panel' => 'closed'], 'hints' => ['Put the side panel back.'], 'why' => 'A closed case keeps dust out and air flowing the right way.', 'outcome' => 'TS-3.2'],
            ['id' => 'reseat', 'only' => ['cable'], 'type' => 'tap', 'target' => 'psu-cable', 'prompt' => 'Push the power cable firmly into the back of the PC.', 'sets' => ['psu-cable' => 'in'],
                'hints' => ['The thick black cable at the back of the PC.'], 'why' => 'A cable that is half in can work one day and not the next. Push until it seats fully.', 'outcome' => 'TS-3.2'],
            ['id' => 'power', 'type' => 'tap', 'target' => 'wall-switch', 'prompt' => 'Switch the power back on at the wall.', 'sets' => ['wall-switch' => 'on'], 'hints' => ['The same switch you turned off.'], 'why' => 'Power comes back only once the case is closed.', 'outcome' => 'TS-3.2'],
            ['id' => 'test', 'type' => 'tap', 'target' => 'pc-power', 'prompt' => 'Start the PC and check that it boots.', 'sets' => ['monitor' => 'ok'], 'hints' => ['Press the power button on the PC again.'], 'why' => 'Always test before you tell the user it is fixed.', 'outcome' => 'TS-2.2', 'lesson' => ['code' => 'TS-L03']],
        ],
    ];

    private function demoActivities(TenantContext $context): void
    {
        $context->runAs(null, function (): void {
            $version = ProgrammeVersion::query()->whereHas('programme', fn ($q) => $q->where('code', 'technical-support'))->where('status', 'published')->first();
            if ($version === null || Activity::query()->where('programme_version_id', $version->id)->exists()) {
                return;
            }
            $catalogue = app(ActivityCatalogue::class);
            foreach ([
                ['lesson', 'TS-L01', 'Logging a support request', 'Turn a phone call into a clear ticket.', 4, 1, ['TS-1.1', 'TS-1.2']],
                ['lesson', 'TS-L02', 'Working safely inside a PC', 'Antistatic strap, power off, and why it matters.', 5, 1, ['TS-2.2']],
                ['scenario', 'TS-S01', 'A PC won’t boot', 'Diagnose the fault and replace the faulty RAM safely.', 12, 2, ['TS-2.2', 'TS-3.2']],
                ['lesson', 'TS-L03', 'Explaining the fix', 'Tell the user what you did, in plain words.', 3, 1, ['TS-6.1', 'TS-6.2']],
            ] as [$kind, $code, $title, $summary, $minutes, $weight, $outcomes]) {
                if ($kind === 'scenario') {
                    $catalogue->publish($catalogue->create($version, ['kind' => $kind, 'code' => $code, 'title' => $title, 'summary' => $summary, 'minutes' => $minutes, 'weight' => $weight,
                        'content' => ScenarioContent::clean(self::DEMO_SCENARIO)[0] + ['i18n' => [], 'review' => ['en' => ['by' => 'Zanele Mthembu (HQ)', 'at' => now()->toIso8601String()]]]], $outcomes));

                    continue;
                }
                $content = self::DEMO_LESSONS[$code] ?? [];
                $blocks = array_map(fn (array $b, int $i) => ['id' => strtolower($code).'b'.$i] + $b, $content, array_keys($content));
                $catalogue->publish($catalogue->create($version, ['kind' => $kind, 'code' => $code, 'title' => $title, 'summary' => $summary, 'minutes' => $minutes, 'weight' => $weight,
                    'content' => $blocks === [] ? [] : ['blocks' => LessonContent::clean($blocks)[0], 'i18n' => [], 'review' => ['en' => ['by' => 'Zanele Mthembu (HQ)', 'at' => now()->toIso8601String()]]]], $outcomes));
            }
        }, platform: true);
    }

    private function demoCohort(TenantContext $context): void
    {
        $tenant = Tenant::query()->where('slug', 'ubuntu')->firstOrFail();
        $context->runAs($tenant, function () use ($tenant): void {
            if (Cohort::query()->exists()) {
                return;
            }
            $admin = User::query()->where('email', 'admin@ubuntu.demo')->firstOrFail();
            $cohort = app(Cohorts::class)->create([
                'name' => 'Technical Support — February 2027', 'centre_id' => Centre::query()->firstOrCreate(['name' => 'Durban campus'])->id,
                'programme_id' => Programme::query()->where('code', 'technical-support')->value('id'), 'starts_on' => today()->addDays(5)->toDateString(),
                'ends_on' => today()->addMonths(12)->toDateString(),
            ], $admin);
            $ids = Learner::query()->orderBy('surname')->limit(6)->pluck('id')->all();
            // The demo learner account (learner@ubuntu.demo) is on the register too, so its home shows the programme.
            $thandiwe = User::query()->where('email', 'learner@ubuntu.demo')->firstOrFail();
            $ids[] = Learner::query()->create(['user_id' => $thandiwe->id, 'first_names' => 'Thandiwe', 'surname' => 'Khumalo', 'id_type' => 'sa_id', 'date_of_birth' => '2001-03-08',
                'gender' => 'female', 'citizenship' => 'sa', 'home_language' => 'zu', 'phone' => '+27825550120', 'status' => Learner::ACTIVE, 'joined_at' => now(), 'popi_agreed_at' => now(), 'created_by' => $admin->id]
                + IdNumbers::protect('sa_id', self::luhn('010308012308')))->id;
            app(Enrolments::class)->enrol($tenant, $cohort, $ids, $admin);
            // QCTO details for three learners, so the cohort shows a realistic mix of complete and missing data.
            $tenant->update(['qcto_sdp_code' => 'SDP-DEMO-0001']);
            foreach (array_slice($ids, 0, 3) as $i => $id) {
                Learner::query()->findOrFail($id)->fill([
                    'title' => ['Ms', 'Ms', 'Mr'][$i], 'equity_encrypted' => 'BA', 'disability_encrypted' => ['status' => 'N', 'rating' => null],
                    'socioeconomic_code' => '02', 'province_code' => '5', 'stats_area_code' => '59910', 'flc_code' => '03', 'popi_agreed_at' => now()->subDays(2),
                    'home_address' => ['line1' => (12 + $i).' Florida Road', 'line2' => 'Morningside', 'line3' => 'Durban', 'postal_code' => '4001'],
                ])->save();
            }
            Enrolment::query()->where('learner_id', $ids[0])->update(['started_at' => now(), 'status' => Enrolment::ACTIVE, 'progress' => 12]);
        });
    }

    /** Replace the last digit of a 13-digit test ID with its correct Luhn check digit. */
    private static function luhn(string $id): string
    {
        $base = substr($id, 0, 12);
        $sum = 0;
        foreach (str_split(strrev($base)) as $i => $c) {
            $d = (int) $c * ($i % 2 === 0 ? 2 : 1);
            $sum += $d > 9 ? $d - 9 : $d;
        }

        return $base.((10 - $sum % 10) % 10);
    }

    /** Three packages on sale: a fixed-term bundle, a monthly courses-only plan, and a simulator pack. */
    private function demoPackages(): void
    {
        $packages = [
            ['code' => 'technical-support-12-months', 'name' => 'Technical Support — simulators and courses, 12 months', 'programme' => 'technical-support', 'sim' => true, 'course' => true, 'billing' => 'term', 'term' => 12, 'min' => 10,
                'prices' => [10 => 250000, 50 => 210000, 200 => 180000], 'description' => 'Everything a cohort needs for a full year: every simulator scenario and every course.'],
            ['code' => 'data-analytics-courses-monthly', 'name' => 'Data Analytics — courses, monthly', 'programme' => 'data-analytics', 'sim' => false, 'course' => true, 'billing' => 'monthly', 'term' => null, 'min' => 5,
                'prices' => [5 => 19900, 50 => 16900], 'description' => 'Animated courses with quizzes and recaps, billed per learner per month.'],
            ['code' => 'cybersecurity-defender-simulators-6-months', 'name' => 'Cybersecurity Defender — simulators, 6 months', 'programme' => 'cybersecurity-defender', 'sim' => true, 'course' => false, 'billing' => 'term', 'term' => 6, 'min' => 10,
                'prices' => [10 => 145000, 100 => 120000], 'description' => 'Hands-on incident response simulators for a six-month workplace component.'],
        ];
        foreach ($packages as $p) {
            if (Package::query()->where('code', $p['code'])->exists()) {
                continue;
            }
            $programme = Programme::query()->where('code', $p['programme'])->first();
            if ($programme === null) {
                continue;
            }
            $package = Package::query()->create([
                'code' => $p['code'], 'name' => $p['name'], 'description' => $p['description'], 'programme_id' => $programme->id,
                'includes_simulators' => $p['sim'], 'includes_courses' => $p['course'], 'billing' => $p['billing'], 'term_months' => $p['term'], 'min_seats' => $p['min'],
            ]);
            foreach ($p['prices'] as $from => $cents) {
                $package->prices()->create(['from_seats' => $from, 'unit_price_cents' => $cents]);
            }
            $package->forceFill(['status' => Package::PUBLISHED, 'published_at' => now()])->save();
        }
    }

    /** A believable coverage map for the demo: Technical Support well under way, two others started. */
    private function demoCoverage(): void
    {
        $rows = [
            'technical-support' => [
                'TS-1.1' => ['simulator' => 'linked', 'course' => 'linked'], 'TS-1.2' => ['simulator' => 'linked'],
                'TS-1.3' => ['simulator' => 'in_production', 'course' => 'linked'], 'TS-1.4' => ['course' => 'planned'],
                'TS-2.1' => ['simulator' => 'linked'], 'TS-2.2' => ['simulator' => 'in_production'], 'TS-3.1' => ['course' => 'planned'],
            ],
            'data-analytics' => ['DA-1.1' => ['course' => 'in_production'], 'DA-1.2' => ['course' => 'planned']],
            'cybersecurity-defender' => ['CYD-1.1' => ['simulator' => 'planned']],
        ];
        foreach ($rows as $code => $outcomes) {
            $programme = Programme::query()->where('code', $code)->first();
            if ($programme === null) {
                continue;
            }
            foreach ($outcomes as $outcome => $channels) {
                foreach ($channels as $channel => $status) {
                    OutcomeCoverage::query()->updateOrCreate(
                        ['programme_id' => $programme->id, 'outcome_code' => $outcome, 'channel' => $channel],
                        ['status' => $status],
                    );
                }
            }
        }
    }
}
