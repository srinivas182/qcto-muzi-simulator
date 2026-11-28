<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Identity\ConsentService;
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

        $this->command?->info('Demo ready. Password for every account: '.self::PASSWORD);
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
