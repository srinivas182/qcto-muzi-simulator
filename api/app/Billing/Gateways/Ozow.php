<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Ozow (Instant EFT): form post with a SHA512 HashCheck (fields in Ozow's order, private key appended, lowercased);
 * notifications carry the same kind of hash; payments are confirmed with Ozow's transaction lookup API.
 */
final class Ozow implements Gateway
{
    private const NOTIFY_ORDER = ['SiteCode', 'TransactionId', 'TransactionReference', 'Amount', 'Status', 'Optional1', 'Optional2', 'Optional3', 'Optional4', 'Optional5', 'CurrencyCode', 'IsTest', 'StatusMessage'];

    public function driver(): string
    {
        return 'ozow';
    }

    public function label(): string
    {
        return 'Ozow';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'site_code', 'label' => 'Site code', 'secret' => false],
            ['key' => 'private_key', 'label' => 'Private key', 'secret' => true],
            ['key' => 'api_key', 'label' => 'API key', 'secret' => true],
        ];
    }

    /** @return array{type: 'form', action: string, fields: array<string, string>} */
    public function start(PaymentAttempt $attempt, Invoice $invoice, PaymentGateway $config, string $returnUrl, string $notifyUrl): array
    {
        $fields = [
            'SiteCode' => $config->credential('site_code'),
            'CountryCode' => 'ZA',
            'CurrencyCode' => 'ZAR',
            'Amount' => number_format($attempt->amount_cents / 100, 2, '.', ''),
            'TransactionReference' => $attempt->reference,
            'BankReference' => substr($invoice->number, 0, 20),
            'CancelUrl' => $returnUrl,
            'ErrorUrl' => $returnUrl,
            'SuccessUrl' => $returnUrl,
            'NotifyUrl' => $notifyUrl,
            'IsTest' => $config->isLive() ? 'false' : 'true',
        ];
        $fields['HashCheck'] = self::hash(array_values($fields), $config->credential('private_key'));

        return ['type' => 'form', 'action' => 'https://pay.ozow.com', 'fields' => $fields];
    }

    public function parseNotification(Request $request, PaymentGateway $config): ?array
    {
        $posted = $request->request->all();
        $values = array_map(fn (string $k) => (string) ($posted[$k] ?? ''), self::NOTIFY_ORDER);
        if (! hash_equals(self::hash($values, $config->credential('private_key')), strtolower((string) ($posted['Hash'] ?? '')))) {
            return null;
        }

        return [
            'event_id' => ($posted['TransactionId'] ?? $posted['TransactionReference'] ?? '').':'.($posted['Status'] ?? ''),
            'reference' => $posted['TransactionReference'] ?? null,
            'gateway_reference' => isset($posted['TransactionId']) ? (string) $posted['TransactionId'] : null,
            'payload' => array_intersect_key($posted, array_flip([...self::NOTIFY_ORDER, 'Hash'])),
        ];
    }

    public function confirm(PaymentAttempt $attempt, PaymentGateway $config): array
    {
        $res = Http::withHeaders(['ApiKey' => $config->credential('api_key')])->acceptJson()->timeout(15)
            ->get('https://api.ozow.com/GetTransactionByReference', ['siteCode' => $config->credential('site_code'), 'transactionReference' => $attempt->reference, 'isTest' => $config->isLive() ? 'false' : 'true']);
        $rows = $res->successful() ? (array) $res->json() : [];
        foreach ($rows as $row) {
            if (strtolower((string) ($row['status'] ?? '')) === 'complete') {
                return ['state' => 'paid', 'amount_cents' => (int) round(((float) ($row['amount'] ?? 0)) * 100), 'gateway_reference' => (string) ($row['transactionId'] ?? '')];
            }
        }
        $failed = $rows !== [] && collect($rows)->every(fn ($r) => in_array(strtolower((string) ($r['status'] ?? '')), ['cancelled', 'error', 'abandoned'], true));

        return ['state' => $failed ? 'failed' : 'pending', 'amount_cents' => null, 'gateway_reference' => null];
    }

    public function test(PaymentGateway $config): array
    {
        $res = Http::withHeaders(['ApiKey' => $config->credential('api_key')])->acceptJson()->timeout(10)
            ->get('https://api.ozow.com/GetTransactionByReference', ['siteCode' => $config->credential('site_code'), 'transactionReference' => 'connection-test']);

        return $res->successful() ? ['ok' => true, 'message' => 'Ozow accepted the API key.'] : ['ok' => false, 'message' => 'Ozow refused the site code or API key ('.$res->status().').'];
    }

    /** @param list<string> $values */
    public static function hash(array $values, string $privateKey): string
    {
        return hash('sha512', strtolower(implode('', $values).$privateKey));
    }
}
