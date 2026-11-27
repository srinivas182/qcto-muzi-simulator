<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A general chapter of the guide (framework, mentor's guide, profile index) shown to mentors and providers.
 *
 * @property string $id
 * @property string $slug
 * @property string $title
 * @property string $markdown
 * @property array<string, mixed>|null $source
 * @property int $position
 */
class ReferencePage extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['source' => 'array'];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
