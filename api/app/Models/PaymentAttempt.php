<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "Pay online" attempt for an invoice. `reference` is what we send to the gateway.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $gateway
 * @property string $reference
 * @property string|null $gateway_reference
 * @property int $amount_cents
 * @property string $status
 * @property string|null $outcome
 * @property string|null $created_by
 * @property-read Invoice $invoice
 */
class PaymentAttempt extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['invoice_id', 'gateway', 'reference', 'gateway_reference', 'amount_cents', 'status', 'outcome', 'created_by'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
