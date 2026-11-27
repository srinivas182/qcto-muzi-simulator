<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Which simulator or course covers a workplace outcome (platform data, keyed by the stable outcome code).
 *
 * @property string $id
 * @property string $programme_id
 * @property string $outcome_code
 * @property string $channel
 * @property string $status
 * @property string|null $title
 * @property string|null $updated_by
 */
class OutcomeCoverage extends Model
{
    use HasUuids;

    public const CHANNELS = ['simulator', 'course'];

    public const STATUSES = ['planned', 'in_production', 'linked'];

    protected $table = 'outcome_coverage';

    protected $fillable = ['programme_id', 'outcome_code', 'channel', 'status', 'title', 'updated_by'];
}
