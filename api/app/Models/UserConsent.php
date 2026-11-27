<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $user_id
 * @property string $purpose
 * @property string $version
 * @property bool $granted
 */
class UserConsent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['user_id', 'purpose', 'version', 'granted', 'ip_address', 'user_agent'];

    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }

    public static function allowsPlatformRows(): bool
    {
        return true;
    }
}
