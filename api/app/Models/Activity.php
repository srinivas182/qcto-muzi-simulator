<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A lesson or simulator scenario of a programme version (HQ content, platform-wide).
 *
 * @property string $id
 * @property string $programme_version_id
 * @property string $kind lesson · scenario
 * @property string $code
 * @property string $title
 * @property string|null $summary
 * @property int $position
 * @property bool $required
 * @property int $weight
 * @property int $minutes
 * @property string $status draft · published · retired
 * @property int $content_version
 * @property array<string, mixed> $content
 * @property Carbon|null $published_at
 * @property-read ProgrammeVersion $version
 */
class Activity extends Model
{
    use HasUuids;

    public const LESSON = 'lesson';

    public const SCENARIO = 'scenario';

    public const PUBLISHED = 'published';

    protected $fillable = ['programme_version_id', 'kind', 'code', 'title', 'summary', 'position', 'required', 'weight', 'minutes', 'status', 'content_version', 'content', 'published_at', 'updated_by'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'weight' => 'integer', 'minutes' => 'integer', 'content_version' => 'integer', 'content' => 'array', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<ProgrammeVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProgrammeVersion::class, 'programme_version_id');
    }

    /** @return list<string> */
    public function outcomeCodes(): array
    {
        /** @var list<string> */
        return DB::table('activity_outcomes')->where('activity_id', $this->id)->orderBy('outcome_code')->pluck('outcome_code')->all();
    }
}
