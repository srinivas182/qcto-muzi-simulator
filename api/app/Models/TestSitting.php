<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $enrolment_id
 * @property string $assessment_task_id
 * @property int $content_version
 * @property int $attempt
 * @property string $locale
 * @property string $status
 * @property Carbon $started_at
 * @property Carbon $deadline_at
 * @property Carbon|null $submitted_at
 * @property bool $late
 * @property list<array{id: string, options: list<int>}> $paper
 * @property array<string, mixed> $answers
 * @property array<string, int> $marks
 * @property int|null $score
 * @property string|null $result
 * @property string|null $marked_by
 * @property Carbon|null $marked_at
 * @property string|null $device
 * @property bool $camera
 * @property array{extra_time: int, read_aloud: bool}|null $accommodations
 * @property Carbon|null $camera_consent_at
 * @property-read AssessmentTask $task
 * @property-read Enrolment $enrolment
 */
class TestSitting extends Model
{
    use BelongsToTenant, HasUuids;

    /** Answers saved on a phone that lost signal may arrive this long after the deadline. */
    public const GRACE_MINUTES = 15;

    protected $fillable = ['enrolment_id', 'assessment_task_id', 'content_version', 'attempt', 'locale', 'status', 'started_at', 'deadline_at', 'submitted_at', 'late', 'paper', 'answers', 'marks', 'score', 'result', 'marked_by', 'marked_at', 'device', 'camera', 'camera_consent_at', 'accommodations'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'deadline_at' => 'datetime', 'submitted_at' => 'datetime', 'marked_at' => 'datetime', 'late' => 'boolean', 'camera' => 'boolean', 'camera_consent_at' => 'datetime', 'accommodations' => 'array', 'paper' => 'array', 'answers' => 'array', 'marks' => 'array'];
    }

    /** @return BelongsTo<AssessmentTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AssessmentTask::class, 'assessment_task_id');
    }

    /** @return BelongsTo<Enrolment, $this> */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(Enrolment::class);
    }
}
