<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only record of a sensitive action. Visibility is enforced by row-level security:
 * a provider sees its own entries; HQ sees all. The database refuses updates and deletes.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $actor_id
 * @property string|null $actor_name
 * @property string|null $support_session_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array', 'meta' => 'array', 'created_at' => 'datetime'];
    }
}
