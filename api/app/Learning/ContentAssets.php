<?php

declare(strict_types=1);

namespace App\Learning;

use App\Models\ContentAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/** Uploads for lessons and scenarios: checked by real file type and size, stored once per content hash. */
final class ContentAssets
{
    public function store(UploadedFile $file, string $kind, User $actor): ContentAsset
    {
        $limits = config("content.limits.{$kind}");
        if (! is_array($limits)) {
            throw ValidationException::withMessages(['kind' => 'Choose picture, audio or video.']);
        }
        [$maxKb, $types] = $limits;
        $mime = (string) $file->getMimeType(); // from the file's content, not its name
        if (! in_array($mime, $types, true)) {
            throw ValidationException::withMessages(['file' => 'This file type is not allowed here.']);
        }
        if ($file->getSize() > $maxKb * 1024) {
            throw ValidationException::withMessages(['file' => "The file is too big for learners' phones. Keep it under {$maxKb} KB."]);
        }
        $sha = (string) hash_file('sha256', (string) $file->getRealPath());
        $existing = ContentAsset::query()->where('sha256', $sha)->first();
        if ($existing !== null) {
            return $existing;
        }
        $ext = $file->guessExtension() ?? 'bin';
        $path = "content/{$kind}/".substr($sha, 0, 2)."/{$sha}.{$ext}";
        Storage::disk((string) config('content.disk'))->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'public']);

        return ContentAsset::query()->create([
            'kind' => $kind, 'path' => $path, 'mime' => $mime, 'bytes' => (int) $file->getSize(), 'sha256' => $sha,
            'name' => mb_substr($file->getClientOriginalName(), 0, 200), 'created_by' => $actor->id,
        ]);
    }
}
