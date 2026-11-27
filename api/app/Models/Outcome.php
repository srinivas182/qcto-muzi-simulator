<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A workplace outcome: something the learner must be seen doing at work (e.g. TS-1.2).
 *
 * @property string $id
 * @property string $code
 * @property int $position
 * @property string $statement
 * @property string|null $plain_words
 */
class Outcome extends Model
{
    use HasUuids;

    protected $guarded = ['id'];
}
