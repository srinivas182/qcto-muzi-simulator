<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $kind occupational · legacy · skills_programme · related · note
 * @property string|null $saqa_id
 * @property string|null $title
 * @property int|null $nqf_level
 * @property int|null $credits
 * @property string $source_text
 * @property int $position
 */
class QualificationRef extends Model
{
    use HasUuids;

    public const KINDS = ['occupational', 'legacy', 'skills_programme', 'related', 'note'];

    protected $guarded = ['id'];
}
