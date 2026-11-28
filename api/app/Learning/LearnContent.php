<?php

declare(strict_types=1);

namespace App\Learning;

use App\Models\Activity;
use App\Models\Attempt;
use App\Models\ContentAsset;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Tenancy\DomainService;
use Illuminate\Support\Collection;

/**
 * What a learner's device receives: one lesson, one scenario, or a whole programme as an offline pack.
 * The same shapes are used online and offline, so the apps render them the same way.
 */
final class LearnContent
{
    /** Pack format version: raise it when the shape changes so devices fetch a fresh pack. */
    public const PACK_FORMAT = 1;

    public static function locale(mixed $requested): string
    {
        return in_array($requested, LessonContent::LOCALES, true) ? (string) $requested : 'en';
    }

    /**
     * @param  Collection<int, Activity>|null  $scenarios
     * @return array<string, mixed>
     */
    public static function lesson(Activity $a, string $locale, ?Collection $scenarios = null): array
    {
        $blocks = LessonContent::localise($a->content ?? [], $locale);
        $assetIds = collect($blocks)->flatMap(fn ($b) => [$b['asset_id'] ?? null, $b['low_asset_id'] ?? null, $b['audio_asset_id'] ?? null])
            ->filter(fn ($id) => preg_match('/^[0-9a-f-]{36}$/', (string) $id) === 1)->unique();
        $scenarios ??= self::published($a->programme_version_id, Activity::SCENARIO);

        return [
            'id' => $a->id, 'code' => $a->code, 'title' => $a->title, 'minutes' => $a->minutes, 'content_version' => $a->content_version,
            'locale' => $locale === 'en' || isset($a->content['review'][$locale]['by']) ? $locale : 'en', 'blocks' => $blocks,
            'assets' => ContentAsset::query()->whereIn('id', $assetIds)->get()->mapWithKeys(fn (ContentAsset $x) => [$x->id => $x->present()])->all(),
            'scenarios' => $scenarios->map(fn (Activity $s) => ['id' => $s->id, 'code' => $s->code, 'title' => $s->title])->keyBy('code')->all(),
        ];
    }

    /**
     * @param  Collection<int, Activity>|null  $lessons
     * @return array<string, mixed>
     */
    public static function scenario(Enrolment $e, Activity $a, string $locale, ?Collection $lessons = null): array
    {
        $best = Attempt::query()->where('enrolment_id', $e->id)->where('activity_id', $a->id)->whereNotNull('finished_at')
            ->get(['mode', 'score', 'result'])->groupBy('mode')
            ->map(fn ($list) => ['best' => (int) $list->max('score'), 'tries' => $list->count(), 'passed' => $list->contains(fn (Attempt $x) => (bool) ($x->result['passed'] ?? false))]);
        $lessons ??= self::published($a->programme_version_id, Activity::LESSON);

        return [
            'id' => $a->id, 'code' => $a->code, 'title' => $a->title, 'summary' => $a->summary, 'minutes' => $a->minutes, 'content_version' => $a->content_version,
            'locale' => $locale === 'en' || isset($a->content['review'][$locale]['by']) ? $locale : 'en',
            'content' => ScenarioContent::localise($a->content ?? [], $locale),
            'lessons' => $lessons->pluck('id', 'code')->all(),
            'results' => ['practice' => $best->get('practice'), 'job' => $best->get('job')],
        ];
    }

    /**
     * A whole programme for offline use: every published lesson and scenario in one language, the files they
     * use, and the app's 3D view. `version` changes whenever any of it does, so a device can ask "anything new?"
     * with If-None-Match and get a 304 back.
     *
     * @return array<string, mixed>
     */
    public static function pack(Tenant $tenant, Enrolment $e, string $locale): array
    {
        $lessons = self::published($e->programme_version_id, Activity::LESSON);
        $scenarios = self::published($e->programme_version_id, Activity::SCENARIO);
        $lessonData = $lessons->map(fn (Activity $a) => self::lesson($a, $locale, $scenarios))->keyBy('id')->all();
        $scenarioData = $scenarios->map(fn (Activity $a) => array_diff_key(self::scenario($e, $a, $locale, $lessons), ['results' => 1]))->keyBy('id')->all();
        $assets = collect($lessonData)->flatMap(fn ($l) => array_values($l['assets']))->unique('id')->values()->all();
        $runtime = ['url' => 'https://'.DomainService::primaryHost($tenant).'/embed/sim-3d.html', 'scenes' => array_keys(ScenarioContent::SCENES)];
        $body = ['lessons' => $lessonData, 'scenarios' => $scenarioData, 'assets' => $assets, 'runtime' => $runtime];

        return [
            'format' => self::PACK_FORMAT, 'enrolment_id' => $e->id, 'locale' => $locale,
            'version' => substr(hash('sha256', self::PACK_FORMAT.'|'.json_encode($body)), 0, 16),
            'built_at' => now()->toIso8601String(),
            'bytes' => strlen((string) json_encode($body)) + (int) collect($assets)->sum('bytes'),
        ] + $body;
    }

    /** @return Collection<int, Activity> */
    private static function published(string $versionId, string $kind): Collection
    {
        return Activity::query()->where('programme_version_id', $versionId)->where('kind', $kind)->where('status', Activity::PUBLISHED)->orderBy('position')->get();
    }
}
