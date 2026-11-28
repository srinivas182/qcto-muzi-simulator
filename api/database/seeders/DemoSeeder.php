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
use App\Models\BillingSetting;
use App\Models\Centre;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\OutcomeCoverage;
use App\Models\Package;
use App\Models\Programme;
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
