<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The seller and payment details printed on every invoice (one row, edited by Super Admin).
 *
 * @property string $id
 * @property string|null $seller_name
 * @property string|null $registration_number
 * @property string|null $vat_number
 * @property array<string, string|null> $address
 * @property string|null $email
 * @property string|null $phone
 * @property array<string, string|null> $bank
 * @property string $vat_rate
 * @property string $invoice_prefix
 * @property string $credit_note_prefix
 * @property int $due_days
 * @property string|null $updated_by
 */
class BillingSetting extends Model
{
    use HasUuids;

    protected $fillable = ['seller_name', 'registration_number', 'vat_number', 'address', 'email', 'phone', 'bank', 'vat_rate', 'invoice_prefix', 'credit_note_prefix', 'due_days', 'updated_by'];

    protected $attributes = ['key' => 'default', 'address' => '{}', 'bank' => '{}', 'vat_rate' => 15, 'invoice_prefix' => 'INV-', 'credit_note_prefix' => 'CN-', 'due_days' => 7];

    protected function casts(): array
    {
        return ['address' => 'array', 'bank' => 'array', 'due_days' => 'integer'];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(['key' => 'default'], ['vat_rate' => config('billing.vat_rate')]);
    }

    public function vatRate(): float
    {
        return (float) $this->vat_rate;
    }
}
