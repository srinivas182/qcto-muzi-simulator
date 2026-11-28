<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * PayFast: form post to its hosted page; ITN notifications signed with MD5 (and the passphrase), then checked by
 * posting them back to PayFast's validate endpoint.
 */
final class PayFast implements Gateway
{
    public function driver(): string
    {
        return 'payfast';
    }

    public function label(): string
    {
        return 'PayFast';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'secret' => false],
            ['key' => 'merchant_key', 'label' => 'Merchant key', 'secret' => true],
            ['key' => 'passphrase', 'label' => 'Passphrase', 'secret' => true],
        ];
    }

    /** @return array{type: 'form', action: string, fields: array<string, string>} */
    public function start(PaymentAttempt $attempt, Invoice $invoice, PaymentGateway $config, string $returnUrl, string $notifyUrl): array
    {
        $fields = array_filter([
            'merchant_id' => $config->credential('merchant_id'),
            'merchant_key' => $config->credential('merchant_key'),
            'return_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'notify_url' => $notifyUrl,
            'email_address' => $invoice->buyer['email'] ?? null,
            'm_payment_id' => $attempt->reference,
            'amount' => number_format($attempt->amount_cents / 100, 2, '.', ''),
            'item_name' => "Invoice {$invoice->number}",
        ], fn ($v) => $v !== null && $v !== '');
        $fields['signature'] = self::signature($fields, $config->credential('passphrase'));

        return ['type' => 'form', 'action' => $this->host($config).'/eng/process', 'fields' => $fields];
    }

    public function parseNotification(Request $request, PaymentGateway $config): ?array
    {
        $posted = $request->request->all();
        $signature = (string) ($posted['signature'] ?? '');
        unset($posted['signature']);
        if (! hash_equals(self::signature($posted, $config->credential('passphrase')), $signature)) {
            return null;
        }

        return [
            'event_id' => 'itn:'.($posted['pf_payment_id'] ?? sha1(serialize($posted))).':'.($posted['payment_status'] ?? ''),
            'reference' => $posted['m_payment_id'] ?? null,
            'gateway_reference' => isset($posted['pf_payment_id']) ? (string) $posted['pf_payment_id'] : null,
            'payload' => $posted + ['signature' => $signature],
        ];
    }

    /** PayFast has no simple status lookup: re-validate the latest ITN for this attempt with PayFast itself. */
    public function confirm(PaymentAttempt $attempt, PaymentGateway $config): array
    {
        $event = PaymentEvent::query()->where('gateway', 'payfast')->where('payment_attempt_id', $attempt->id)->latest()->first();
        if ($event === null) {
            return ['state' => 'pending', 'amount_cents' => null, 'gateway_reference' => null];
        }
        $itn = $event->payload;
        $valid = Http::asForm()->timeout(15)->post($this->host($config).'/eng/query/validate', $itn)->body() === 'VALID';
        $status = $itn['payment_status'] ?? '';

        return [
            'state' => $valid && $status === 'COMPLETE' ? 'paid' : ($status === 'CANCELLED' ? 'failed' : 'pending'),
            'amount_cents' => $valid ? (int) round(((float) ($itn['amount_gross'] ?? 0)) * 100) : null,
            'gateway_reference' => isset($itn['pf_payment_id']) ? (string) $itn['pf_payment_id'] : null,
        ];
    }

    public function test(PaymentGateway $config): array
    {
        $headers = ['merchant-id' => $config->credential('merchant_id'), 'version' => 'v1', 'timestamp' => now()->format('Y-m-d\TH:i:sP')];
        $sign = $headers;
        ksort($sign);
        $headers['signature'] = self::signature($sign, $config->credential('passphrase'));
        $res = Http::withHeaders($headers)->timeout(10)->get('https://api.payfast.co.za/ping'.($config->isLive() ? '' : '?testing=true'));

        return $res->successful() ? ['ok' => true, 'message' => 'PayFast accepted the merchant details.'] : ['ok' => false, 'message' => 'PayFast refused the merchant details ('.$res->status().').'];
    }

    /** @param array<string, mixed> $fields */
    public static function signature(array $fields, string $passphrase): string
    {
        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key.'='.urlencode(trim((string) $value));
        }
        if ($passphrase !== '') {
            $pairs[] = 'passphrase='.urlencode(trim($passphrase));
        }

        return md5(implode('&', $pairs));
    }

    private function host(PaymentGateway $config): string
    {
        return $config->isLive() ? 'https://www.payfast.co.za' : 'https://sandbox.payfast.co.za';
    }
}
