<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Money received against an invoice: an EFT recorded by HQ, or an online payment confirmed by a gateway.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $method
 * @property string|null $gateway
 * @property string|null $gateway_reference
 * @property int $amount_cents
 * @property string|null $reference
 * @property Carbon $paid_on
 * @property string|null $recorded_by
 */
class Payment extends Model
{
    use BelongsToTenant, HasUuids;

    public const EFT = 'eft';

    public const GATEWAY = 'gateway';

    protected $fillable = ['invoice_id', 'method', 'gateway', 'gateway_reference', 'amount_cents', 'reference', 'paid_on', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_on' => 'date'];
    }
}
