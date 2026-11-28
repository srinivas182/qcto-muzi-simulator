<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A training provider (tenant) on the platform.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property array<string, mixed> $branding
 * @property array<string, mixed> $settings
 * @property string|null $accreditation_number
 * @property string|null $qcto_sdp_code
 * @property string|null $vat_number
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $legal_name
 * @property string|null $registration_number
 * @property array<string, string|null> $address
 * @property Carbon|null $accreditation_expires_at
 * @property string|null $billing_email
 * @property array{mode: string, days: int} $payment_terms
 * @property string|null $status_reason
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $created_at
 */
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'qcto_sdp_code',
        'name', 'slug', 'status', 'accreditation_number', 'vat_number',
        'contact_email', 'contact_phone', 'branding', 'settings',
        'legal_name', 'registration_number', 'address', 'accreditation_expires_at', 'billing_email',
        'payment_terms', 'status_reason', 'status_changed_at',
    ];

    public const STATUSES = [self::STATUS_TRIAL, self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_ARCHIVED];

    /** Allowed status changes. Archiving is final. */
    public const TRANSITIONS = [
        self::STATUS_TRIAL => [self::STATUS_ACTIVE, self::STATUS_SUSPENDED, self::STATUS_ARCHIVED],
        self::STATUS_ACTIVE => [self::STATUS_SUSPENDED, self::STATUS_ARCHIVED],
        self::STATUS_SUSPENDED => [self::STATUS_ACTIVE, self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [],
    ];

    protected $attributes = [
        'status' => self::STATUS_TRIAL,
        'branding' => '{}',
        'settings' => '{}',
        'address' => '{}',
        'payment_terms' => '{"mode": "pay_first", "days": 0}',
    ];

    protected function casts(): array
    {
        return [
            'branding' => 'array',
            'settings' => 'array',
            'address' => 'array',
            'payment_terms' => 'array',
            'accreditation_expires_at' => 'date',
            'status_changed_at' => 'datetime',
        ];
    }

    /** @return HasMany<TenantDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    /** @return HasMany<Centre, $this> */
    public function centres(): HasMany
    {
        return $this->hasMany(Centre::class);
    }

    /** HQ switch (off by default): may this provider add its own outcomes and evidence to programmes? */
    public function allowsProgrammeAdditions(): bool
    {
        return ($this->settings['programme_additions'] ?? false) === true;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * QCTO accreditation state, for warnings to HQ and the provider.
     *
     * @return array{state: 'missing'|'ok'|'expiring'|'expired', days_left: int|null}
     */
    public function accreditationState(): array
    {
        if ($this->accreditation_number === null || $this->accreditation_expires_at === null) {
            return ['state' => 'missing', 'days_left' => null];
        }
        $days = (int) now()->startOfDay()->diffInDays($this->accreditation_expires_at->copy()->startOfDay(), false);

        return ['state' => $days < 0 ? 'expired' : ($days <= 60 ? 'expiring' : 'ok'), 'days_left' => $days];
    }

    public function isReachable(): bool
    {
        return $this->status !== self::STATUS_ARCHIVED;
    }
}
