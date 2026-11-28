<?php

declare(strict_types=1);

namespace Tests\Feature\Learners;

use App\Identity\ConsentService;
use App\Models\Learner;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 3.5: learners (add, import, SA ID checks, POPIA-safe ID numbers, invitations). */
final class LearnerTest extends AccessTestCase
{
    private const ID = '8001015009087'; // valid test SA ID: 1 Jan 1980, male, citizen

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        [, $this->admin] = $this->person($this->ubuntu, 'tp_admin');
    }

    /** @param array<string, mixed> $over */
    private function add(array $over = [], ?string $token = null, string $host = 'ubuntu.simuwork.test'): TestResponse
    {
        return $this->fresh()->postJson($this->url('/learners', $host), $over + [
            'first_names' => 'Sipho Andile', 'surname' => 'Ndlovu', 'id_number' => self::ID, 'phone' => '082 555 0101', 'home_language' => 'zu',
        ], $this->bearer($token ?? $this->admin));
    }

    /** Valid SA ID numbers for tests: date + sequence + citizen + 8 + Luhn digit. */
    private static function saId(int $n): string
    {
        $base = '9501015'.str_pad((string) ($n % 1000), 3, '0', STR_PAD_LEFT).'08';
        $sum = 0;
        foreach (str_split(strrev($base)) as $i => $c) {
            $d = (int) $c * ($i % 2 === 0 ? 2 : 1);
            $sum += $d > 9 ? $d - 9 : $d;
        }

        return $base.((10 - $sum % 10) % 10);
    }

    public function test_adding_a_learner_reads_the_sa_id_and_never_stores_it_in_plain_text(): void
    {
        $this->add()->assertCreated()
            ->assertJsonPath('data.name', 'Sipho Andile Ndlovu')->assertJsonPath('data.id_masked', '•••••••••9087')
            ->assertJsonPath('data.date_of_birth', '1980-01-01')->assertJsonPath('data.gender', 'male')->assertJsonPath('data.citizenship', 'sa')
            ->assertJsonPath('data.phone', '+27825550101')->assertJsonPath('data.status', 'invited')->assertJsonMissingPath('data.id_number');

        $row = (array) app(TenantContext::class)->runAs($this->ubuntu, fn () => DB::table('learners')->first());
        $this->assertStringNotContainsString(self::ID, json_encode($row) ?: '');
        $logs = app(TenantContext::class)->runAs(null, fn () => DB::table('audit_logs')->get(), platform: true);
        $this->assertStringNotContainsString(self::ID, json_encode($logs) ?: '');
        $this->assertAudited('learners.added', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_invalid_and_duplicate_details_are_refused_with_clear_messages(): void
    {
        $this->add(['id_number' => '8001015009088'])->assertUnprocessable()->assertJsonValidationErrors('id_number');
        $this->add()->assertCreated();
        $this->add(['phone' => '083 000 1111'])->assertUnprocessable()->assertJsonPath('errors.id_number.0', 'Already a learner: Sipho Andile Ndlovu.');
        $this->add(['id_number' => self::saId(1)])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->add(['id_number' => self::saId(2), 'phone' => null, 'email' => null])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->add(['id_type' => 'passport', 'id_number' => 'A1234567', 'phone' => '083 000 2222', 'date_of_birth' => '1999-04-05'])->assertCreated()
            ->assertJsonPath('data.citizenship', 'foreign')->assertJsonPath('data.date_of_birth', '1999-04-05')->assertJsonPath('data.id_masked', '••••4567');
    }

    public function test_the_same_person_can_learn_at_two_providers_but_each_sees_only_its_own(): void
    {
        [, $lebone] = $this->person($this->lebone, 'tp_admin');
        $id = $this->add()->json('data.id');
        $this->add([], $lebone, 'lebone.simuwork.test')->assertCreated();

        $this->fresh()->getJson($this->url("/learners/{$id}", 'lebone.simuwork.test'), $this->bearer($lebone))->assertNotFound();
        $this->fresh()->getJson($this->url('/learners', 'lebone.simuwork.test'), $this->bearer($lebone))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_import_checks_every_row_first_then_adds_only_the_good_ones_quickly(): void
    {
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['first_names' => "Learner {$i}", 'surname' => 'Mokoena', 'id_number' => self::saId($i), 'phone' => '082'.str_pad((string) (1000000 + $i), 7, '0', STR_PAD_LEFT)];
        }
        $rows[10]['id_number'] = '9501015000000'; // bad check digit
        $rows[20]['id_number'] = $rows[19]['id_number']; // repeated within the file
        $rows[30]['phone'] = 'not a phone';

        $started = microtime(true);
        $preview = $this->postJson($this->url('/learners/import'), ['rows' => $rows, 'dry_run' => true], $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.ready', 497)->assertJsonPath('data.failed', 3)->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.rows.20.errors.id_number', 'The same ID number is on row 20.');
        $this->assertSame(0, app(TenantContext::class)->runAs($this->ubuntu, fn () => Learner::query()->count()));

        $this->fresh()->postJson($this->url('/learners/import'), ['rows' => $rows, 'dry_run' => false], $this->bearer($this->admin))->assertOk()->assertJsonPath('data.created', 497);
        $this->assertLessThan(10, microtime(true) - $started, 'Previewing and importing 500 learners must take under 10 seconds.');
        $this->assertNotEmpty($preview->json('data.rows.10.errors.id_number'));
    }

    public function test_revealing_an_id_number_needs_its_own_permission_and_is_recorded(): void
    {
        $id = $this->add()->json('data.id');
        [, $manager] = $this->person($this->ubuntu, 'centre_manager');

        $this->fresh()->postJson($this->url("/learners/{$id}/reveal-id"), [], $this->bearer($manager))->assertForbidden();
        $this->fresh()->postJson($this->url("/learners/{$id}/reveal-id"), [], $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.id_number', self::ID)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertAudited('learners.id_revealed', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_an_invited_learner_joins_and_becomes_active(): void
    {
        $id = $this->add(['phone' => null, 'email' => 'sipho@example.test'])->json('data.id');
        $url = (string) $this->fresh()->postJson($this->url("/learners/{$id}/invite"), [], $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.channel', 'email')->json('data.url');
        $token = basename($url);

        $consents = array_fill_keys(array_keys(app(ConsentService::class)->current()), true);
        $this->fresh()->postJson($this->url("/invitations/{$token}/accept"), ['password' => 'a-long-password-2026', 'consents' => $consents])->assertSuccessful();

        $learner = app(TenantContext::class)->runAs($this->ubuntu, fn () => Learner::query()->findOrFail($id));
        $this->assertSame('active', $learner->status);
        $this->assertNotNull($learner->user_id);
        $this->assertTrue(app(TenantContext::class)->runAs($this->ubuntu, fn () => User::query()->findOrFail($learner->user_id)->hasPermission('learning.use')));
    }

    public function test_deactivating_a_learner_signs_them_out_and_staff_without_rights_cannot_add(): void
    {
        $id = $this->add()->json('data.id');
        $this->fresh()->postJson($this->url("/learners/{$id}/status"), ['active' => false], $this->bearer($this->admin))->assertOk()->assertJsonPath('data.status', 'inactive');

        [, $facilitator] = $this->person($this->ubuntu, 'facilitator');
        $this->add(['id_number' => self::saId(9), 'phone' => '082 999 0000'], $facilitator)->assertForbidden();
        $this->fresh()->getJson($this->url('/learners'), $this->bearer($facilitator))->assertOk(); // facilitators may look
    }
}
