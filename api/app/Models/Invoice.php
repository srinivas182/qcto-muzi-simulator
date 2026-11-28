<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A tax invoice. Its content never changes (database trigger); only status, payments and reminders move.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $number
 * @property string $kind
 * @property string|null $order_id
 * @property string|null $licence_pool_id
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property array<string, mixed> $seller
 * @property array<string, mixed> $buyer
 * @property list<array{description: string, quantity: int, unit_cents: int, amount_cents: int}> $lines
 * @property int $subtotal_cents
 * @property string $vat_rate
 * @property int $vat_cents
 * @property int $total_cents
 * @property int $paid_cents
 * @property string $status
 * @property Carbon|null $paid_at
 * @property int $reminder_level
 * @property Carbon $created_at
 * @property-read Order|null $order
 * @property-read Tenant $tenant
 */
class Invoice extends Model
{
    use BelongsToTenant, HasUuids;

    public const ISSUED = 'issued';

    public const PAID = 'paid';

    public const CREDITED = 'credited';

    protected $fillable = ['number', 'kind', 'order_id', 'licence_pool_id', 'period_start', 'period_end', 'issue_date', 'due_date', 'seller', 'buyer', 'lines', 'subtotal_cents', 'vat_rate', 'vat_cents', 'total_cents', 'paid_cents', 'status', 'paid_at', 'reminder_level'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'issue_date' => 'date', 'due_date' => 'date', 'paid_at' => 'datetime',
            'seller' => 'array', 'buyer' => 'array', 'lines' => 'array',
            'subtotal_cents' => 'integer', 'vat_cents' => 'integer', 'total_cents' => 'integer', 'paid_cents' => 'integer', 'reminder_level' => 'integer',
        ];
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('created_at');
    }

    /** @return HasOne<CreditNote, $this> */
    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class);
    }

    public function balanceCents(): int
    {
        return max(0, $this->total_cents - $this->paid_cents);
    }

    public function daysOverdue(): int
    {
        if ($this->status !== self::ISSUED) {
            return 0;
        }

        return max(0, (int) $this->due_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), false));
    }
}
