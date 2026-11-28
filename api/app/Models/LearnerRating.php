<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A learner's 3-question rating of a simulator (pilot measure, Sprint 4.7c), each 1–5.
 *
 * @property string $id
 * @property string $enrolment_id
 * @property string $activity_id
 * @property int $easy
 * @property int $real
 * @property int $ready
 */
class LearnerRating extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['enrolment_id', 'activity_id', 'easy', 'real', 'ready'];

    protected function casts(): array
    {
        return ['easy' => 'integer', 'real' => 'integer', 'ready' => 'integer'];
    }
}
