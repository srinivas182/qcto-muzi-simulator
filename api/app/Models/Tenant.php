<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A training provider (tenant) on the platform.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property array<string, mixed> $branding
 * @property array<string, mixed> $settings
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
        'name', 'slug', 'status', 'accreditation_number', 'vat_number',
        'contact_email', 'contact_phone', 'branding', 'settings',
    ];

    protected $attributes = [
        'status' => self::STATUS_TRIAL,
        'branding' => '{}',
        'settings' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'branding' => 'array',
            'settings' => 'array',
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

    public function isReachable(): bool
    {
        return $this->status !== self::STATUS_ARCHIVED;
    }
}
