<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A read-only link to a learner's job-ready checklist, made by the learner for a mentor or employer.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $enrolment_id
 * @property string $token_hash
 * @property string|null $label
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property int $views
 * @property Carbon|null $last_viewed_at
 * @property Carbon $created_at
 * @property-read Enrolment $enrolment
 */
class ChecklistShare extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['enrolment_id', 'token_hash', 'label', 'expires_at', 'revoked_at', 'views', 'last_viewed_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime', 'last_viewed_at' => 'datetime', 'views' => 'integer'];
    }

    /** @return BelongsTo<Enrolment, $this> */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
