<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cancels an invoice in full (the only way to correct one).
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $number
 * @property Carbon $issue_date
 * @property int $subtotal_cents
 * @property int $vat_cents
 * @property int $total_cents
 * @property string $reason
 * @property string|null $created_by
 */
class CreditNote extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['invoice_id', 'number', 'issue_date', 'subtotal_cents', 'vat_cents', 'total_cents', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'subtotal_cents' => 'integer', 'vat_cents' => 'integer', 'total_cents' => 'integer'];
    }
}
