<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A payment gateway Super Admin has configured. Credentials are encrypted with the app key and never returned
 * by the API (only whether each one is set, and its last 4 characters).
 *
 * @property string $id
 * @property string $driver
 * @property bool $enabled
 * @property string $mode
 * @property array<string, string>|null $credentials
 * @property int $display_order
 * @property Carbon|null $last_tested_at
 * @property bool|null $last_test_ok
 * @property string|null $last_test_message
 * @property string|null $updated_by
 */
class PaymentGateway extends Model
{
    use HasUuids;

    protected $fillable = ['driver', 'enabled', 'mode', 'credentials', 'display_order', 'last_tested_at', 'last_test_ok', 'last_test_message', 'updated_by'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean', 'credentials' => 'encrypted:array', 'display_order' => 'integer',
            'last_tested_at' => 'datetime', 'last_test_ok' => 'boolean',
        ];
    }

    public function credential(string $key): string
    {
        return (string) ($this->credentials[$key] ?? '');
    }

    public function isLive(): bool
    {
        return $this->mode === 'live';
    }
}
