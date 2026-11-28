<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A summative assessment task in a programme version's assessment plan (HQ content, no tenant).
 *
 * @property string $id
 * @property string $programme_version_id
 * @property string $code
 * @property string $title
 * @property string $component
 * @property string $kind
 * @property string|null $activity_id
 * @property int $position
 * @property bool $required
 * @property array<string, mixed> $settings
 * @property array<string, mixed> $content
 * @property array<string, mixed>|null $draft
 * @property Carbon|null $draft_updated_at
 * @property string $status
 * @property int $content_version
 * @property Carbon|null $published_at
 * @property-read Activity|null $activity
 * @property-read ProgrammeVersion $version
 */
class AssessmentTask extends Model
{
    use HasUuids;

    public const COMPONENTS = ['knowledge', 'practical', 'workplace'];

    /** Which kinds of task each component allows. */
    public const KINDS = ['knowledge' => ['test'], 'practical' => ['simulator', 'observation'], 'workplace' => ['logbook']];

    protected $fillable = ['programme_version_id', 'code', 'title', 'component', 'kind', 'activity_id', 'position', 'required', 'settings', 'content', 'draft', 'draft_updated_at', 'status', 'content_version', 'published_at', 'updated_by'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'settings' => 'array', 'content' => 'array', 'draft' => 'array', 'draft_updated_at' => 'datetime', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<Activity, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }

    /** @return BelongsTo<ProgrammeVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProgrammeVersion::class, 'programme_version_id');
    }

    /** @return list<string> */
    public function outcomeCodes(): array
    {
        return DB::table('assessment_task_outcomes')->where('assessment_task_id', $this->id)->orderBy('outcome_code')->pluck('outcome_code')->all();
    }
}
