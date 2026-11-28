<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An assessor's decision on a practical task (Sprint 5.2).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $enrolment_id
 * @property string $assessment_task_id
 * @property int $round
 * @property string $result competent · not_yet
 * @property string|null $attempt_id
 * @property list<array{item: string, met: bool, note: string|null}>|null $observation
 * @property string|null $feedback
 * @property list<array{type: string, ref: string, label: string}> $links
 * @property string|null $evidence_hash
 * @property string $assessor_id
 * @property Carbon $declared_at
 * @property Carbon $created_at
 * @property-read Enrolment $enrolment
 * @property-read AssessmentTask $task
 * @property-read User $assessor
 */
class AssessmentDecision extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['enrolment_id', 'assessment_task_id', 'round', 'result', 'attempt_id', 'observation', 'feedback', 'links', 'evidence_hash', 'assessor_id', 'declared_at'];

    protected $attributes = ['links' => '[]'];

    protected function casts(): array
    {
        return ['round' => 'integer', 'observation' => 'array', 'links' => 'array', 'declared_at' => 'datetime'];
    }

    /** @return BelongsTo<Enrolment, $this> */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }

    /** @return BelongsTo<AssessmentTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AssessmentTask::class, 'assessment_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessor_id');
    }
}
