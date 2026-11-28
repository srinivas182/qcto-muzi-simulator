<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\BillingSetting;
use App\Models\Invoice;
use App\Models\LicencePool;
use App\Models\Package;
use App\Models\Tenant;
use App\Notifications\BillingReminder;
use App\Notifications\InvoiceIssued;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 3.3: billing settings, orders, invoices, EFT payments, credit notes, pools and the daily run. */
final class BillingTest extends AccessTestCase
{
    private string $super;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Carbon::setTestNow('2027-01-01 09:00:00');
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        [, $this->super] = $this->person(null, 'super_admin', ['email' => 'srini@hq.test']);
        [, $this->admin] = $this->person($this->ubuntu, 'tp_admin');
        $this->package('ts-12', 'technical-support', 'term', 12, 10, [10 => 250000, 50 => 210000]);
        $this->package('da-monthly', 'data-analytics', 'monthly', null, 5, [5 => 19900]);
        BillingSetting::current()->update(['seller_name' => 'Muzi Skills Tech (Pty) Ltd', 'vat_number' => '4987654321', 'bank' => ['bank' => 'FNB', 'account_number' => '62812345678', 'branch_code' => '250655']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<int, int> $bands */
    private function package(string $code, string $programme, string $billing, ?int $term, int $min, array $bands): void
    {
        $package = Package::query()->create([
            'code' => $code, 'name' => strtoupper($code), 'programme_id' => DB::table('programmes')->where('code', $programme)->value('id'),
            'includes_simulators' => true, 'includes_courses' => true, 'billing' => $billing, 'term_months' => $term, 'min_seats' => $min,
        ]);
        foreach ($bands as $from => $cents) {
            $package->prices()->create(['from_seats' => $from, 'unit_price_cents' => $cents]);
        }
        $package->forceFill(['status' => 'published', 'published_at' => now()])->save();
    }

    /** @param array<string, mixed> $body */
    private function order(array $body, string $host = 'ubuntu.simuwork.test', ?string $token = null): TestResponse
    {
        return $this->fresh()->postJson($this->url('/orders', $host), $body, $this->bearer($token ?? $this->admin));
    }

    private function pay(string $invoiceId, int $cents): TestResponse
    {
        return $this->fresh()->postJson($this->url("/platform/billing/invoices/{$invoiceId}/payments", self::HQ), [
            'amount_cents' => $cents, 'reference' => 'FNB 0042', 'paid_on' => today()->toDateString(),
        ], $this->bearer($this->super));
    }

    private function in(Tenant $tenant, callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $fn);
    }

    public function test_pay_first_order_goes_live_when_the_eft_is_recorded(): void
    {
        $res = $this->order(['package' => 'ts-12', 'seats' => 60])->assertCreated()
            ->assertJsonPath('invoice.number', 'INV-000001')->assertJsonPath('invoice.subtotal_cents', 12600000)
            ->assertJsonPath('invoice.vat_cents', 1890000)->assertJsonPath('invoice.total_cents', 14490000)
            ->assertJsonPath('invoice.due_date', '2027-01-08')->assertJsonPath('pool.status', 'pending');
        Notification::assertSentTimes(InvoiceIssued::class, 1);

        $id = (string) $res->json('invoice.id');
        $this->pay($id, 10000000)->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.balance_cents', 4490000);
        $this->pay($id, 4490000)->assertOk()->assertJsonPath('data.status', 'paid');
        $this->pay($id, 1)->assertUnprocessable();

        $this->fresh()->getJson($this->url('/licences'), $this->bearer($this->admin))->assertOk()
            ->assertJsonPath('data.0.status', 'active')->assertJsonPath('data.0.seats', 60)
            ->assertJsonPath('data.0.starts_on', '2027-01-01')->assertJsonPath('data.0.ends_on', '2027-12-31');
        $this->assertAudited('billing.invoice_paid', ['tenant_id' => $this->ubuntu->id]);

        $invoice = $this->in($this->ubuntu, fn () => Invoice::query()->firstOrFail());
        $this->assertSame('Muzi Skills Tech (Pty) Ltd', $invoice->seller['name']);
        $this->assertSame('Ubuntu Skills Academy', $invoice->buyer['trading_name']);
    }

    public function test_on_account_providers_get_seats_at_once_with_their_payment_days(): void
    {
        $this->ubuntu->forceFill(['payment_terms' => ['mode' => 'on_account', 'days' => 30]])->save();

        $this->order(['package' => 'ts-12', 'seats' => 10])->assertCreated()
            ->assertJsonPath('pool.status', 'active')->assertJsonPath('pool.seats', 10)->assertJsonPath('invoice.due_date', '2027-01-31');
    }

    public function test_fixed_term_top_ups_cost_only_the_days_left_at_the_locked_price(): void
    {
        $this->ubuntu->forceFill(['payment_terms' => ['mode' => 'on_account', 'days' => 30]])->save();
        $first = $this->order(['package' => 'ts-12', 'seats' => 10]);
        $pool = $first->json('pool.id');
        $this->pay((string) $first->json('invoice.id'), (int) $first->json('invoice.total_cents'))->assertOk(); // otherwise July is "30 days overdue"

        Carbon::setTestNow('2027-07-02 10:00:00'); // 183 of 365 days left
        $this->order(['pool' => $pool, 'seats' => 5])->assertCreated()
            ->assertJsonPath('invoice.subtotal_cents', 5 * (int) round(250000 * 183 / 365))
            ->assertJsonPath('pool.seats', 15);
    }

    public function test_monthly_pools_prorate_the_first_month_then_invoice_on_the_first(): void
    {
        Carbon::setTestNow('2027-03-15 08:00:00'); // 17 of 31 days left
        $res = $this->order(['package' => 'da-monthly', 'seats' => 5])->assertCreated()
            ->assertJsonPath('invoice.subtotal_cents', 5 * (int) round(19900 * 17 / 31))
            ->assertJsonPath('invoice.period_start', '2027-03-15')->assertJsonPath('invoice.period_end', '2027-03-31');
        $this->pay((string) $res->json('invoice.id'), (int) $res->json('invoice.total_cents'))->assertOk();
        $pool = (string) $res->json('pool.id');

        $this->fresh()->patchJson($this->url("/licences/{$pool}"), ['seats_next_month' => 4], $this->bearer($this->admin))->assertUnprocessable(); // below the package minimum
        $this->fresh()->patchJson($this->url("/licences/{$pool}"), ['seats_next_month' => 8], $this->bearer($this->admin))->assertOk();

        Carbon::setTestNow('2027-04-01 02:15:00');
        $this->artisan('billing:daily')->assertSuccessful();
        $this->artisan('billing:daily')->assertSuccessful(); // twice in a day: still one invoice
        $april = $this->in($this->ubuntu, fn () => Invoice::query()->where('kind', 'monthly')->get());
        $this->assertCount(1, $april);
        $this->assertSame(8 * 19900, $april[0]->subtotal_cents);
        $this->assertSame(8, $this->in($this->ubuntu, fn () => LicencePool::query()->findOrFail($pool)->seats));

        $this->fresh()->postJson($this->url("/licences/{$pool}/cancel"), ['cancel' => true], $this->bearer($this->admin))->assertOk()->assertJsonPath('data.ends_on', '2027-04-30');
        Carbon::setTestNow('2027-05-01 02:15:00');
        $this->artisan('billing:daily')->assertSuccessful();
        $this->assertSame('expired', $this->in($this->ubuntu, fn () => LicencePool::query()->findOrFail($pool)->status));
        $this->assertCount(1, $this->in($this->ubuntu, fn () => Invoice::query()->where('kind', 'monthly')->get()));
    }

    public function test_an_unpaid_invoice_is_credited_and_its_seats_taken_back(): void
    {
        $id = $this->order(['package' => 'ts-12', 'seats' => 10])->json('invoice.id');

        $this->fresh()->postJson($this->url("/platform/billing/invoices/{$id}/credit", self::HQ), ['reason' => 'Ordered the wrong programme'], $this->bearer($this->super))
            ->assertOk()->assertJsonPath('data.status', 'credited')->assertJsonPath('data.credit_note.number', 'CN-000001');
        $this->assertSame('cancelled', $this->in($this->ubuntu, fn () => LicencePool::query()->firstOrFail()->status));
        $this->pay((string) $id, 100)->assertUnprocessable();

        $this->fresh()->get($this->url("/invoices/{$id}/credit-note.pdf"), $this->bearer($this->admin))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_invoices_are_legal_documents_that_never_change(): void
    {
        $this->order(['package' => 'ts-12', 'seats' => 10])->assertCreated();

        $this->expectExceptionMessage('cannot be changed');
        $this->in($this->ubuntu, fn () => DB::transaction(fn () => DB::table('invoices')->update(['total_cents' => 1])));
    }

    public function test_numbers_run_on_without_gaps_across_providers(): void
    {
        [, $lebone] = $this->person($this->lebone, 'tp_admin');
        $this->order(['package' => 'ts-12', 'seats' => 10])->assertJsonPath('invoice.number', 'INV-000001');
        $this->order(['package' => 'ts-12', 'seats' => 10], 'lebone.simuwork.test', $lebone)->assertJsonPath('invoice.number', 'INV-000002');
        $this->order(['package' => 'ts-12', 'seats' => 9])->assertUnprocessable(); // below the minimum: no number used
        $this->order(['package' => 'ts-12', 'seats' => 11])->assertJsonPath('invoice.number', 'INV-000003');
    }

    public function test_providers_only_see_their_own_invoices_and_hq_sees_all(): void
    {
        [, $lebone] = $this->person($this->lebone, 'tp_admin');
        $id = $this->order(['package' => 'ts-12', 'seats' => 10])->json('invoice.id');

        $this->fresh()->getJson($this->url("/invoices/{$id}", 'lebone.simuwork.test'), $this->bearer($lebone))->assertNotFound();
        $this->fresh()->getJson($this->url('/invoices', 'lebone.simuwork.test'), $this->bearer($lebone))->assertOk()->assertJsonCount(0, 'data');
        $this->fresh()->getJson($this->url('/platform/billing/invoices', self::HQ), $this->bearer($this->super))->assertOk()->assertJsonCount(1, 'data');
        $pdf = $this->fresh()->get($this->url("/invoices/{$id}/pdf"), $this->bearer($this->admin))->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_reminders_then_a_block_after_thirty_days_overdue(): void
    {
        $this->order(['package' => 'ts-12', 'seats' => 10])->assertCreated(); // due 8 Jan

        Carbon::setTestNow('2027-01-08 02:15:00');
        $this->artisan('billing:daily');
        Carbon::setTestNow('2027-01-15 02:15:00');
        $this->artisan('billing:daily');
        Notification::assertSentTimes(BillingReminder::class, 2);
        $this->order(['package' => 'ts-12', 'seats' => 10])->assertCreated(); // 7 days overdue: still allowed

        Carbon::setTestNow('2027-02-08 02:15:00');
        $this->artisan('billing:daily');
        $this->order(['package' => 'ts-12', 'seats' => 10])->assertStatus(423);
        $this->fresh()->getJson($this->url('/licences'), $this->bearer($this->admin))->assertJsonPath('account.blocked', true);
        $this->fresh()->getJson($this->url('/platform/billing/summary', self::HQ), $this->bearer($this->super))->assertOk()
            ->assertJsonPath('data.blocked_providers.0.id', $this->ubuntu->id);
    }

    public function test_only_super_admin_changes_billing_settings_and_old_invoices_keep_their_seller(): void
    {
        [, $finance] = $this->person(null, 'support_finance', ['email' => 'thabo@hq.test']);
        $this->order(['package' => 'ts-12', 'seats' => 10])->assertCreated();

        $this->fresh()->getJson($this->url('/platform/billing/settings', self::HQ), $this->bearer($finance))->assertForbidden();
        $this->fresh()->patchJson($this->url('/platform/billing/settings', self::HQ), ['seller_name' => 'Renamed (Pty) Ltd', 'vat_rate' => 16, 'bank' => ['account_number' => '12ab']], $this->bearer($this->super))
            ->assertUnprocessable()->assertJsonValidationErrors('bank.account_number');
        $this->fresh()->patchJson($this->url('/platform/billing/settings', self::HQ), ['seller_name' => 'Renamed (Pty) Ltd', 'vat_rate' => 16], $this->bearer($this->super))
            ->assertOk()->assertJsonPath('data.vat_rate', 16);

        $this->order(['package' => 'ts-12', 'seats' => 10])->assertJsonPath('invoice.vat_cents', 400000);
        $sellers = $this->in($this->ubuntu, fn () => Invoice::query()->orderBy('number')->get()->map(fn (Invoice $i) => $i->seller['name'])->all());
        $this->assertSame(['Muzi Skills Tech (Pty) Ltd', 'Renamed (Pty) Ltd'], $sellers);
        $this->assertAudited('billing.settings_changed');
    }

    public function test_staff_without_licence_rights_cannot_buy_or_see_invoices(): void
    {
        [, $facilitator] = $this->person($this->ubuntu, 'facilitator');
        $this->order(['package' => 'ts-12', 'seats' => 10], 'ubuntu.simuwork.test', $facilitator)->assertForbidden();
        $this->fresh()->getJson($this->url('/invoices'), $this->bearer($facilitator))->assertForbidden();
    }
}
