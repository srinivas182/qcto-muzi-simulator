<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Evidence the mentor should see for a duty area.
 *
 * @property string $id
 * @property int $position
 * @property string $description
 * @property string $type document · photo · observation · sign_off · feedback · record
 * @property int $min_count
 */
class EvidenceRequirement extends Model
{
    use HasUuids;

    public const TYPES = ['document', 'photo', 'observation', 'sign_off', 'feedback', 'record'];

    protected $guarded = ['id'];
}
