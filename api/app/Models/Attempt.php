<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One go at an activity by a learner. client_id is made on the device so offline syncs are safe to repeat.
 * Once finished, an attempt never changes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $client_id
 * @property string $enrolment_id
 * @property string $activity_id
 * @property string $mode lesson · practice · job
 * @property int $content_version
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property bool $completed
 * @property int|null $score
 * @property int $duration_seconds
 * @property array<string, mixed> $result
 * @property string|null $device
 * @property Carbon $updated_at
 */
class Attempt extends Model
{
    use BelongsToTenant, HasUuids;

    public const MODES = ['lesson', 'practice', 'job'];

    protected $fillable = ['client_id', 'enrolment_id', 'activity_id', 'mode', 'content_version', 'started_at', 'finished_at', 'completed', 'score', 'duration_seconds', 'result', 'device'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'completed' => 'boolean', 'score' => 'integer', 'duration_seconds' => 'integer', 'result' => 'array'];
    }
}
