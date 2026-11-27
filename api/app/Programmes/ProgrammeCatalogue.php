<?php

declare(strict_types=1);

namespace App\Programmes;

use App\Models\Programme;
use App\Models\ProgrammeVersion;
use Illuminate\Support\Facades\Cache;

/**
 * Reads of published programme content. Published versions never change (database-enforced), so their
 * full content is cached forever by version id; the catalogue list is cached until the next publish.
 */
final class ProgrammeCatalogue
{
    private const LIST_KEY = 'programmes:published:list';

    /** @return list<array<string, mixed>> */
    public function published(): array
    {
        /** @var list<array<string, mixed>> */
        return Cache::rememberForever(self::LIST_KEY, function (): array {
            return Programme::query()
                ->where('status', 'active')
                ->whereHas('published')
                ->with(['published' => fn ($q) => $q->withCount(['dutyAreas', 'outcomes', 'evidence'])])
                ->orderBy('position')
                ->get()
                ->map(fn (Programme $p) => ProgrammePresenter::summary($p, $p->published))
                ->values()
                ->all();
        });
    }

    /**
     * Full published content, or null when the programme has no published version.
     *
     * @return array<string, mixed>|null
     */
    public function publishedVersion(Programme $programme): ?array
    {
        $versionId = ProgrammeVersion::query()->where('programme_id', $programme->id)->where('status', ProgrammeVersion::PUBLISHED)->value('id');
        if ($versionId === null) {
            return null;
        }

        /** @var array<string, mixed> */
        return Cache::rememberForever("programmes:version:{$versionId}", fn () => ProgrammePresenter::version(ProgrammeVersion::query()->findOrFail($versionId)));
    }

    public function forgetList(): void
    {
        Cache::forget(self::LIST_KEY);
    }
}
