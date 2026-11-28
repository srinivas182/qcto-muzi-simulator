<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A picture, narration recording or video used in lessons and scenarios. Stored once per content hash, with a
 * file name that never changes, so phones can cache it forever.
 *
 * @property string $id
 * @property string $kind image · audio · video
 * @property string $path
 * @property string $mime
 * @property int $bytes
 * @property string $sha256
 * @property string|null $name
 */
class ContentAsset extends Model
{
    use HasUuids;

    protected $fillable = ['kind', 'path', 'mime', 'bytes', 'sha256', 'name', 'created_by'];

    public function url(): string
    {
        return Storage::disk((string) config('content.disk'))->url($this->path);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'url' => $this->url(), 'bytes' => $this->bytes, 'name' => $this->name, 'mime' => $this->mime];
    }
}
