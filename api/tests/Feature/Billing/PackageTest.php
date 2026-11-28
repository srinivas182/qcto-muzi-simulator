<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\PriceCalculator;
use App\Models\Package;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 3.2: HQ packages and price list, provider shop and price quotes. */
final class PackageTest extends AccessTestCase
{
    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        [, $this->super] = $this->person(null, 'super_admin', ['email' => 'srini@hq.test']);
    }

    private function hq(string $path = ''): string
    {
        return $this->url('/platform/packages'.$path, self::HQ);
    }

    /** @param array<string, mixed> $overrides */
    private function draft(array $overrides = []): string
    {
        $code = $this->fresh()->postJson($this->hq(), $overrides + [
            'name' => 'Technical Support — Simulators + Courses (12 months)', 'programme' => 'technical-support',
            'includes_simulators' => true, 'includes_courses' => true, 'billing' => 'term', 'term_months' => 12, 'min_seats' => 10,
        ], $this->bearer($this->super))->assertCreated()->json('data.code');
        $this->fresh()->putJson($this->hq("/{$code}/prices"), ['prices' => [
            ['from_seats' => 10, 'unit_price_cents' => 250000], ['from_seats' => 50, 'unit_price_cents' => 210000], ['from_seats' => 200, 'unit_price_cents' => 180000],
        ]], $this->bearer($this->super))->assertOk();

        return (string) $code;
    }

    public function test_hq_drafts_prices_and_publishes_a_package(): void
    {
        $code = $this->draft();
        $this->assertSame('technical-support-simulators-courses-12-months', $code);

        $this->fresh()->postJson($this->hq("/{$code}/publish"), [], $this->bearer($this->super))->assertOk()
            ->assertJsonPath('data.status', 'published')->assertJsonCount(3, 'data.prices')
            ->assertJsonPath('data.programme.prefix', 'TS');
        $this->assertAudited('packages.published');
    }

    public function test_published_packages_are_frozen_even_in_the_database(): void
    {
        $code = $this->draft();
        $this->postJson($this->hq("/{$code}/publish"), [], $this->bearer($this->super))->assertOk();

        $this->fresh()->patchJson($this->hq("/{$code}"), ['min_seats' => 1], $this->bearer($this->super))->assertUnprocessable();
        $this->fresh()->putJson($this->hq("/{$code}/prices"), ['prices' => [['from_seats' => 1, 'unit_price_cents' => 100]]], $this->bearer($this->super))->assertUnprocessable();

        $this->expectExceptionMessage('frozen');
        DB::transaction(fn () => DB::table('package_prices')->update(['unit_price_cents' => 1]));
    }

    public function test_publishing_needs_a_price_for_the_minimum_seats(): void
    {
        $code = $this->fresh()->postJson($this->hq(), [
            'name' => 'Data Analytics courses', 'programme' => 'data-analytics', 'includes_simulators' => false, 'includes_courses' => true, 'billing' => 'monthly', 'min_seats' => 5,
        ], $this->bearer($this->super))->assertCreated()->assertJsonPath('data.term_months', null)->json('data.code');

        $this->fresh()->postJson($this->hq("/{$code}/publish"), [], $this->bearer($this->super))->assertUnprocessable()->assertJsonValidationErrors('prices');
        $this->fresh()->putJson($this->hq("/{$code}/prices"), ['prices' => [['from_seats' => 10, 'unit_price_cents' => 19900]]], $this->bearer($this->super))->assertOk();
        $this->fresh()->postJson($this->hq("/{$code}/publish"), [], $this->bearer($this->super))->assertUnprocessable()->assertJsonValidationErrors('prices');
    }

    public function test_a_package_needs_some_content_and_a_term_when_billed_by_term(): void
    {
        $base = ['name' => 'Empty', 'programme' => 'technical-support', 'billing' => 'term', 'min_seats' => 1];
        $this->postJson($this->hq(), $base + ['includes_simulators' => true, 'includes_courses' => true], $this->bearer($this->super))->assertUnprocessable()->assertJsonValidationErrors('term_months');
        $this->fresh()->postJson($this->hq(), $base + ['term_months' => 6, 'includes_simulators' => false, 'includes_courses' => false], $this->bearer($this->super))->assertUnprocessable()->assertJsonValidationErrors('includes_simulators');
    }

    public function test_duplicate_makes_a_new_draft_with_the_same_prices_and_retire_keeps_history(): void
    {
        $code = $this->draft();
        $this->postJson($this->hq("/{$code}/publish"), [], $this->bearer($this->super))->assertOk();

        $copy = $this->fresh()->postJson($this->hq("/{$code}/duplicate"), [], $this->bearer($this->super))->assertCreated()
            ->assertJsonPath('data.status', 'draft')->assertJsonCount(3, 'data.prices')->json('data.code');
        $this->assertSame("{$code}-2", $copy);
        $this->fresh()->postJson($this->hq("/{$code}/retire"), [], $this->bearer($this->super))->assertOk()->assertJsonPath('data.status', 'retired');
        $this->fresh()->deleteJson($this->hq("/{$code}"), [], $this->bearer($this->super))->assertUnprocessable();
        $this->fresh()->deleteJson($this->hq("/{$copy}"), [], $this->bearer($this->super))->assertNoContent();
    }

    public function test_providers_see_only_published_packages_and_get_correct_quotes(): void
    {
        $published = $this->draft();
        $this->postJson($this->hq("/{$published}/publish"), [], $this->bearer($this->super))->assertOk();
        $this->draft(['name' => 'Still a draft']);
        [, $admin] = $this->person($this->ubuntu, 'tp_admin');

        $this->fresh()->getJson($this->url('/shop'), $this->bearer($admin))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', $published)->assertJsonPath('vat_rate', 15);

        // 60 seats → R2 100 band; subtotal R126 000; VAT R18 900; total R144 900; R175/learner/month over 12 months.
        $this->fresh()->getJson($this->url("/shop/{$published}/quote?seats=60"), $this->bearer($admin))->assertOk()->assertJson(['data' => [
            'unit_price_cents' => 210000, 'band_from' => 50, 'subtotal_cents' => 12600000, 'vat_cents' => 1890000, 'total_cents' => 14490000, 'per_seat_per_month_cents' => 17500,
        ]]);
        $this->fresh()->getJson($this->url("/shop/{$published}/quote?seats=5"), $this->bearer($admin))->assertUnprocessable()->assertJsonValidationErrors('seats');

        [, $facilitator] = $this->person($this->ubuntu, 'facilitator');
        $this->fresh()->getJson($this->url('/shop'), $this->bearer($facilitator))->assertForbidden();
    }

    public function test_vat_rounds_half_up_once(): void
    {
        $this->assertSame(15, PriceCalculator::vat(100, 15));
        $this->assertSame(2, PriceCalculator::vat(10, 15)); // 1.5 → 2
        $this->assertSame(1, PriceCalculator::vat(9, 15)); // 1.35 → 1
    }

    public function test_only_package_managers_change_the_price_list(): void
    {
        [, $finance] = $this->person(null, 'support_finance', ['email' => 'thabo@hq.test']);
        $this->getJson($this->hq(), $this->bearer($finance))->assertOk();
        $this->fresh()->postJson($this->hq(), ['name' => 'Nope'], $this->bearer($finance))->assertForbidden();
        $this->assertSame(0, Package::query()->count());
    }
}
