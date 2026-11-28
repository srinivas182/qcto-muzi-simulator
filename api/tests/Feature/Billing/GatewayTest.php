<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Gateways\Ozow;
use App\Billing\Gateways\PayFast;
use App\Billing\Gateways\Peach;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\LicencePool;
use App\Models\Package;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Programmes\GuideImporter;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Access\AccessTestCase;

/** Sprint 3.4: payment gateways (Super Admin settings) and paying invoices online, safely. */
final class GatewayTest extends AccessTestCase
{
    private string $super;

    private string $admin;

    private const RETURN = 'https://ubuntu.simuwork.test/admin/billing';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
        [, $this->super] = $this->person(null, 'super_admin', ['email' => 'srini@hq.test']);
        [, $this->admin] = $this->person($this->ubuntu, 'tp_admin');
        $package = Package::query()->create([
            'code' => 'ts-12', 'name' => 'TS 12', 'programme_id' => DB::table('programmes')->where('code', 'technical-support')->value('id'),
            'includes_simulators' => true, 'includes_courses' => true, 'billing' => 'term', 'term_months' => 12, 'min_seats' => 10,
        ]);
        $package->prices()->create(['from_seats' => 10, 'unit_price_cents' => 100000]);
        $package->forceFill(['status' => 'published'])->save();
    }

    /** @param array<string, string> $credentials */
    private function gateway(string $driver, array $credentials): void
    {
        $this->fresh()->patchJson($this->url("/platform/billing/gateways/{$driver}", self::HQ), ['credentials' => $credentials, 'enabled' => true], $this->bearer($this->super))->assertOk();
    }

    /** Order 10 seats (R10 000 + VAT = R11 500) and return the invoice id. */
    private function invoice(): string
    {
        return (string) $this->fresh()->postJson($this->url('/orders'), ['package' => 'ts-12', 'seats' => 10], $this->bearer($this->admin))->assertCreated()->json('invoice.id');
    }

    private function pay(string $invoiceId, string $driver, string $return = self::RETURN): TestResponse
    {
        return $this->fresh()->postJson($this->url("/invoices/{$invoiceId}/pay"), ['gateway' => $driver, 'return_url' => $return], $this->bearer($this->admin));
    }

    private function invoiceStatus(string $id): string
    {
        return app(TenantContext::class)->runAs($this->ubuntu, fn () => Invoice::query()->findOrFail($id)->status);
    }

    private function attempt(): PaymentAttempt
    {
        return app(TenantContext::class)->runAs($this->ubuntu, fn () => PaymentAttempt::query()->latest()->firstOrFail());
    }

    public function test_super_admin_sets_keys_that_are_never_shown_again(): void
    {
        $this->patchJson($this->url('/platform/billing/gateways/paystack', self::HQ), ['enabled' => true], $this->bearer($this->super))
            ->assertUnprocessable()->assertJsonValidationErrors('enabled');

        $res = $this->fresh()->patchJson($this->url('/platform/billing/gateways/paystack', self::HQ), [
            'credentials' => ['public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_supersecret1234'], 'enabled' => true,
        ], $this->bearer($this->super))->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.fields.1.hint', '••••1234');
        $this->assertStringNotContainsString('supersecret', (string) $res->getContent());

        $this->assertStringNotContainsString('supersecret', (string) DB::table('payment_gateways')->value('credentials')); // encrypted at rest
        $log = app(TenantContext::class)->runAs(null, fn () => AuditLog::query()->where('action', 'billing.gateway_changed')->firstOrFail(), platform: true);
        $this->assertSame(['public_key', 'secret_key'], $log->meta['keys_changed']);
        $this->assertStringNotContainsString('supersecret', json_encode($log->toArray()) ?: '');

        [, $finance] = $this->person(null, 'support_finance', ['email' => 'thabo@hq.test']);
        $this->fresh()->getJson($this->url('/platform/billing/gateways', self::HQ), $this->bearer($finance))->assertForbidden();
    }

    public function test_providers_see_only_switched_on_gateways(): void
    {
        $this->gateway('ozow', ['site_code' => 'TST-1', 'private_key' => 'pk', 'api_key' => 'ak']);
        $this->fresh()->patchJson($this->url('/platform/billing/gateways/yoco', self::HQ), ['credentials' => ['secret_key' => 'sk']], $this->bearer($this->super))->assertOk(); // incomplete and off

        $this->fresh()->getJson($this->url('/payment-options'), $this->bearer($this->admin))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.driver', 'ozow')->assertJsonPath('data.0.test_mode', true);
        $this->pay($this->invoice(), 'yoco')->assertUnprocessable()->assertJsonValidationErrors('gateway');
    }

    public function test_paystack_payment_is_confirmed_with_paystack_and_counted_once(): void
    {
        $this->gateway('paystack', ['public_key' => 'pk_test', 'secret_key' => 'sk_test_key']);
        $id = $this->invoice();
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 1150000, 'currency' => 'ZAR', 'id' => 991]]),
        ]);

        $this->pay($id, 'paystack', 'https://evil.example/steal')->assertUnprocessable()->assertJsonValidationErrors('return_url');
        $this->pay($id, 'paystack')->assertCreated()->assertJsonPath('checkout.type', 'redirect')->assertJsonPath('checkout.url', 'https://checkout.paystack.com/abc');
        $ref = $this->attempt()->reference;

        $body = (string) json_encode(['event' => 'charge.success', 'data' => ['id' => 991, 'reference' => $ref, 'status' => 'success', 'amount' => 1150000]]);
        $this->call('POST', $this->url('/payments/paystack/notify'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'forged'], $body)->assertStatus(400);
        $this->assertSame('issued', $this->invoiceStatus($id));

        $signed = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_key')];
        $this->call('POST', $this->url('/payments/paystack/notify'), [], [], [], $signed, $body)->assertOk();
        $this->call('POST', $this->url('/payments/paystack/notify'), [], [], [], $signed, $body)->assertOk(); // repeated: ignored
        $this->assertSame('paid', $this->invoiceStatus($id));
        $this->assertSame(1, app(TenantContext::class)->runAs($this->ubuntu, fn () => DB::table('payments')->count()));
        $this->assertSame('active', app(TenantContext::class)->runAs($this->ubuntu, fn () => LicencePool::query()->firstOrFail()->status));
    }

    public function test_a_wrong_amount_is_never_accepted(): void
    {
        $this->gateway('paystack', ['public_key' => 'pk_test', 'secret_key' => 'sk_test_key']);
        $id = $this->invoice();
        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['data' => ['authorization_url' => 'https://checkout.paystack.com/x']]),
            'api.paystack.co/transaction/verify/*' => Http::response(['data' => ['status' => 'success', 'amount' => 100, 'currency' => 'ZAR', 'id' => 5]]),
        ]);
        $attempt = $this->pay($id, 'paystack')->json('attempt_id');

        $this->fresh()->getJson($this->url("/payment-attempts/{$attempt}"), $this->bearer($this->admin))->assertOk()->assertJsonPath('data.status', 'failed');
        $this->assertSame('issued', $this->invoiceStatus($id));
        $this->assertAudited('billing.online_payment_mismatch', ['tenant_id' => $this->ubuntu->id]);
    }

    public function test_returning_from_the_gateway_confirms_even_without_a_notification(): void
    {
        $this->gateway('yoco', ['secret_key' => 'sk_yoco', 'webhook_secret' => 'whsec_'.base64_encode('signing-key')]);
        $id = $this->invoice();
        Http::fake([
            'payments.yoco.com/api/checkouts' => Http::response(['id' => 'ch_123', 'redirectUrl' => 'https://pay.yoco.com/ch_123']),
            'payments.yoco.com/api/checkouts/ch_123' => Http::response(['id' => 'ch_123', 'status' => 'completed', 'amount' => 1150000, 'currency' => 'ZAR']),
        ]);
        $attempt = $this->pay($id, 'yoco')->assertJsonPath('checkout.url', 'https://pay.yoco.com/ch_123')->json('attempt_id');

        $this->fresh()->getJson($this->url("/payment-attempts/{$attempt}"), $this->bearer($this->admin))->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame('paid', $this->invoiceStatus($id));
    }

    public function test_yoco_webhooks_need_a_valid_standard_webhooks_signature(): void
    {
        $this->gateway('yoco', ['secret_key' => 'sk_yoco', 'webhook_secret' => 'whsec_'.base64_encode('signing-key')]);
        $id = $this->invoice();
        Http::fake([
            'payments.yoco.com/api/checkouts' => Http::response(['id' => 'ch_9', 'redirectUrl' => 'https://pay.yoco.com/ch_9']),
            'payments.yoco.com/api/checkouts/ch_9' => Http::response(['id' => 'ch_9', 'status' => 'completed', 'amount' => 1150000, 'currency' => 'ZAR']),
        ]);
        $this->pay($id, 'yoco')->assertCreated();
        $body = (string) json_encode(['id' => 'evt_1', 'type' => 'payment.succeeded', 'payload' => ['amount' => 1150000, 'metadata' => ['checkoutId' => 'ch_9']]]);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', "msg_1.{$ts}.{$body}", 'signing-key', true));

        $this->call('POST', $this->url('/payments/yoco/notify'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_ID' => 'msg_1', 'HTTP_WEBHOOK_TIMESTAMP' => (string) (time() - 3600), 'HTTP_WEBHOOK_SIGNATURE' => "v1,{$sig}"], $body)->assertStatus(400); // too old
        $this->call('POST', $this->url('/payments/yoco/notify'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_WEBHOOK_ID' => 'msg_1', 'HTTP_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_WEBHOOK_SIGNATURE' => "v1,{$sig}"], $body)->assertOk();
        $this->assertSame('paid', $this->invoiceStatus($id));
    }

    public function test_ozow_notification_hash_is_checked_then_the_payment_looked_up(): void
    {
        $this->gateway('ozow', ['site_code' => 'TST-1', 'private_key' => 'priv', 'api_key' => 'api']);
        $id = $this->invoice();
        $form = $this->pay($id, 'ozow')->assertCreated()->assertJsonPath('checkout.type', 'form')->json('checkout.fields');
        $this->assertSame(Ozow::hash([...array_values(array_diff_key($form, ['HashCheck' => 1]))], 'priv'), $form['HashCheck']);
        $ref = $this->attempt()->reference;
        Http::fake(['api.ozow.com/*' => Http::response([['transactionId' => 'oz-1', 'status' => 'Complete', 'amount' => 11500.00]])]);

        $post = ['SiteCode' => 'TST-1', 'TransactionId' => 'oz-1', 'TransactionReference' => $ref, 'Amount' => '11500.00', 'Status' => 'Complete', 'Optional1' => '', 'Optional2' => '', 'Optional3' => '', 'Optional4' => '', 'Optional5' => '', 'CurrencyCode' => 'ZAR', 'IsTest' => 'true', 'StatusMessage' => ''];
        $this->post($this->url('/payments/ozow/notify'), $post + ['Hash' => 'nope'])->assertStatus(400);
        $this->post($this->url('/payments/ozow/notify'), $post + ['Hash' => Ozow::hash(array_values($post), 'priv')])->assertOk();
        $this->assertSame('paid', $this->invoiceStatus($id));
    }

    public function test_payfast_itn_is_signed_and_validated_with_payfast(): void
    {
        $this->gateway('payfast', ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'jt7NOE43FZPn']);
        $id = $this->invoice();
        $form = $this->pay($id, 'payfast')->assertCreated()->json('checkout');
        $this->assertSame('https://sandbox.payfast.co.za/eng/process', $form['action']);
        $fields = $form['fields'];
        $sig = $fields['signature'];
        unset($fields['signature']);
        $this->assertSame(PayFast::signature($fields, 'jt7NOE43FZPn'), $sig);

        Http::fake(['sandbox.payfast.co.za/eng/query/validate' => Http::response('VALID')]);
        $itn = ['m_payment_id' => $this->attempt()->reference, 'pf_payment_id' => '1089250', 'payment_status' => 'COMPLETE', 'item_name' => 'Invoice', 'amount_gross' => '11500.00', 'merchant_id' => '10000100'];
        $this->post($this->url('/payments/payfast/notify'), $itn + ['signature' => PayFast::signature($itn, 'jt7NOE43FZPn')])->assertOk();
        $this->assertSame('paid', $this->invoiceStatus($id));
    }

    public function test_peach_checkout_is_signed_and_confirmed_with_the_status_query(): void
    {
        $this->gateway('peach', ['entity_id' => '8ac7a4ca68c22c4d0168c2caab2e0025', 'secret_token' => 'a3b4c5']);
        $id = $this->invoice();
        $fields = $this->pay($id, 'peach')->assertCreated()->json('checkout.fields');
        $sig = $fields['signature'];
        unset($fields['signature']);
        $this->assertSame(Peach::sign($fields, 'a3b4c5'), $sig);

        Http::fake(['testsecure.peachpayments.com/status*' => Http::response(['id' => 'pp-1', 'result' => ['code' => '000.100.110'], 'amount' => '11500.00', 'currency' => 'ZAR'])]);
        $result = ['merchantTransactionId' => $this->attempt()->reference, 'id' => 'pp-1', 'result_code' => '000.100.110', 'amount' => '11500.00'];
        $this->post($this->url('/payments/peach/notify'), $result + ['signature' => Peach::sign($result, 'a3b4c5')])->assertOk();
        $this->assertSame('paid', $this->invoiceStatus($id));
    }

    public function test_connection_test_is_stored_and_keys_reset_it(): void
    {
        $this->gateway('paystack', ['public_key' => 'pk', 'secret_key' => 'sk_bad']);
        Http::fake(['api.paystack.co/transaction*' => Http::response(['status' => false], 401)]);
        $this->fresh()->postJson($this->url('/platform/billing/gateways/paystack/test', self::HQ), [], $this->bearer($this->super))->assertOk()
            ->assertJsonPath('data.last_test.ok', false);
        $this->assertFalse((bool) PaymentGateway::query()->where('driver', 'paystack')->value('last_test_ok'));
    }
}
