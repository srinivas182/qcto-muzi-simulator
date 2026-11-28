<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A notification received from a gateway, kept once per gateway and event id.
 *
 * @property string $id
 * @property string $gateway
 * @property string $event_id
 * @property string|null $payment_attempt_id
 * @property array<string, mixed> $payload
 * @property string|null $outcome
 */
class PaymentEvent extends Model
{
    use HasUuids;

    protected $fillable = ['gateway', 'event_id', 'payment_attempt_id', 'payload', 'outcome'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
