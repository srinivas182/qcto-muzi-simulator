<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person on the platform. tenant_id NULL = HQ (platform) staff.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $password
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property string $locale
 * @property string $status
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToTenant, HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = ['name', 'email', 'phone', 'password', 'locale', 'status', 'email_verified_at', 'phone_verified_at'];

    protected $hidden = ['password', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $attributes = ['locale' => 'en', 'status' => self::STATUS_ACTIVE];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public static function allowsPlatformRows(): bool
    {
        return true;
    }

    /** @return HasMany<UserConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(UserConsent::class);
    }

    public function isPlatformStaff(): bool
    {
        return $this->tenant_id === null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }
}
