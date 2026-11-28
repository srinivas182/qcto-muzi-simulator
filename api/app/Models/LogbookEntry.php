<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One workplace day in a learner's logbook (Sprint 5.3).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $enrolment_id
 * @property string $workplace_id
 * @property string $client_id
 * @property Carbon $day
 * @property float $hours
 * @property list<string> $outcomes
 * @property string|null $note
 * @property string|null $photo_path
 * @property int|null $photo_bytes
 * @property string $status submitted · confirmed · queried
 * @property string|null $mentor_note
 * @property Carbon|null $confirmed_at
 * @property string|null $confirmed_via
 * @property string|null $confirmed_name
 * @property Carbon $created_at
 */
class LogbookEntry extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['enrolment_id', 'workplace_id', 'client_id', 'day', 'hours', 'outcomes', 'note', 'photo_path', 'photo_bytes', 'status', 'mentor_note', 'confirmed_at', 'confirmed_via', 'confirmed_name'];

    protected $attributes = ['outcomes' => '[]', 'status' => 'submitted'];

    protected function casts(): array
    {
        return ['day' => 'date', 'hours' => 'float', 'outcomes' => 'array', 'photo_bytes' => 'integer', 'confirmed_at' => 'datetime'];
    }
}
