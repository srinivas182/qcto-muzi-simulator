<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A provider's answer to one "to confirm before use" item of a programme.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $programme_id
 * @property string $item_key
 * @property string $item_text
 * @property string $status
 * @property string|null $note
 * @property int $version_number
 * @property string|null $answered_by
 * @property Carbon $answered_at
 */
class ProviderProgrammeCheck extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['confirmed', 'not_applicable'];

    protected $fillable = ['programme_id', 'item_key', 'item_text', 'status', 'note', 'version_number', 'answered_by', 'answered_at'];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'version_number' => 'integer'];
    }

    /** Items are identified by their wording, so a reworded item must be confirmed again. */
    public static function keyFor(string $text): string
    {
        return sha1(trim($text));
    }
}
