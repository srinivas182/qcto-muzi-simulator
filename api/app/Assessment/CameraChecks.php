<?php

declare(strict_types=1);

namespace App\Assessment;

use App\Audit\AuditLogger;
use App\Models\AssessmentTask;
use App\Models\SittingPhoto;
use App\Models\Tenant;
use App\Models\TestSitting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The optional camera check for knowledge tests (Sprint 5.1c, POPIA by design).
 *
 * - A provider switches it on once, accepting the data-protection impact assessment we supply (who and when is
 *   recorded). Without that, every test runs at the light level.
 * - A test set to "camera" then needs the learner's consent before it starts. A learner who declines (or has no
 *   camera) cannot sit it online and is told to ask for a supervised sitting at the provider.
 * - The device sends a few small photos (≤ 60 KB each, at most 6 a sitting): one at the start and some at random.
 *   They are stored privately, seen only by people who review assessments, and deleted 90 days after the
 *   result is final.
 */
final class CameraChecks
{
    public const MAX_PHOTOS = 6;

    public const MAX_BYTES = 61_440;

    public const KEEP_DAYS = 90;

    public function __construct(private readonly AuditLogger $audit) {}

    public static function enabledFor(Tenant $tenant): bool
    {
        return (bool) ($tenant->settings['camera_checks']['on'] ?? false);
    }

    /** Does this sitting need the camera (task asks for it and the provider allows it)? */
    public static function required(Tenant $tenant, AssessmentTask $task): bool
    {
        return ($task->settings['integrity'] ?? 'light') === 'camera' && self::enabledFor($tenant);
    }

    public function configure(Tenant $tenant, bool $on, User $actor): void
    {
        $settings = $tenant->settings;
        $settings['camera_checks'] = $on ? ['on' => true, 'accepted_by' => $actor->name, 'accepted_at' => now()->toIso8601String()] : ['on' => false];
        $tenant->update(['settings' => $settings]);
        $this->audit->record($on ? 'academy.camera_checks_on' : 'academy.camera_checks_off', $tenant, null, [], $actor);
    }

    public function photo(TestSitting $s, UploadedFile $file, User $actor): SittingPhoto
    {
        if (! $s->camera || $s->status !== 'open') {
            throw ValidationException::withMessages(['photo' => 'This test does not take photos.']);
        }
        if (SittingPhoto::query()->where('test_sitting_id', $s->id)->count() >= self::MAX_PHOTOS) {
            throw ValidationException::withMessages(['photo' => 'Enough photos for this test.']);
        }
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/webp'], true) || $file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages(['photo' => 'Send a small JPEG or WebP photo.']);
        }
        $path = $file->storeAs("sittings/{$s->tenant_id}/{$s->id}", now()->format('His').'-'.bin2hex(random_bytes(4)).($mime === 'image/webp' ? '.webp' : '.jpg'), 'local');
        $photo = SittingPhoto::query()->create(['test_sitting_id' => $s->id, 'path' => (string) $path, 'bytes' => (int) $file->getSize(), 'taken_at' => now()]);
        $this->audit->record('tests.photo_taken', $s, null, ['bytes' => $photo->bytes], $actor);

        return $photo;
    }

    /** Called when a sitting's result is final: photos are kept for 90 days, then deleted. */
    public static function scheduleDeletion(TestSitting $s): void
    {
        SittingPhoto::query()->where('test_sitting_id', $s->id)->whereNull('delete_after')->update(['delete_after' => now()->addDays(self::KEEP_DAYS)]);
    }

    /** Delete photos that are past their date (daily, per provider). */
    public function purge(): int
    {
        $n = 0;
        foreach (SittingPhoto::query()->whereNotNull('delete_after')->where('delete_after', '<', now())->get() as $p) {
            Storage::disk('local')->delete($p->path);
            $p->delete();
            $n++;
        }

        return $n;
    }
}
