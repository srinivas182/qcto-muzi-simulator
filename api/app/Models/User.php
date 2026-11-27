<?php

declare(strict_types=1);

namespace App\Models;

use App\Authorization\PermissionRegistry;
use App\Tenancy\Concerns\BelongsToTenant;
use App\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /** @return BelongsToMany<Role, $this, RoleAssignment> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->using(RoleAssignment::class)->withPivot(['id', 'centre_id', 'tenant_id'])->withTimestamps();
    }

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    /** @return list<string> */
    public function permissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        $permissions = [];
        foreach ($this->roles()->get() as $role) {
            $permissions = [...$permissions, ...$role->effectivePermissions()];
        }

        return $this->permissionCache = array_values(array_unique($permissions));
    }

    public function hasPermission(string $permission): bool
    {
        // HQ support session: read-only view of the provider (writes are blocked by middleware).
        if ($this->isSupportViewer()) {
            return PermissionRegistry::scopeOf($permission) === 'tenant';
        }

        return in_array($permission, $this->permissions(), true);
    }

    public function isSupportViewer(): bool
    {
        return $this->isPlatformStaff() && app(TenantContext::class)->supportUserId() === $this->id;
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('roles');
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
