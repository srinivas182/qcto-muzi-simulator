<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Http\Controllers\Controller;
use App\Learning\ActivityCatalogue;
use App\Learning\ContentAssets;
use App\Learning\LessonContent;
use App\Learning\LessonStudio;
use App\Models\Activity;
use App\Models\ContentAsset;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** HQ Course Studio: a programme's lessons and scenarios, lesson drafts, AI help, review and publishing. */
final class LessonStudioController extends Controller
{
    public function __construct(
        private readonly LessonStudio $studio,
        private readonly ActivityCatalogue $catalogue,
    ) {}

    public function index(Programme $programme): JsonResponse
    {
        $version = $this->publishedVersion($programme);
        $activities = Activity::query()->where('programme_version_id', $version->id)->orderBy('position')->get();

        return response()->json(['data' => [
            'version' => $version->number,
            'activities' => $activities->map(fn (Activity $a) => $this->summary($a))->all(),
            'outcomes' => DB::table('outcomes')->where('programme_version_id', $version->id)->orderBy('position')->get(['code', 'statement'])->all(),
        ]]);
    }

    public function store(Request $request, Programme $programme): JsonResponse
    {
        $version = $this->publishedVersion($programme);
        $data = $request->validate([
            'kind' => ['required', Rule::in([Activity::LESSON, Activity::SCENARIO])],
            'title' => ['required', 'string', 'max:200'], 'summary' => ['nullable', 'string', 'max:500'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:120'], 'weight' => ['sometimes', 'integer', 'min:1', 'max:10'], 'required' => ['sometimes', 'boolean'],
            'outcomes' => ['required', 'array', 'min:1', 'max:12'], 'outcomes.*' => ['string', 'max:20'],
        ]);
        $letter = $data['kind'] === Activity::SCENARIO ? 'S' : 'L';
        $n = Activity::query()->where('programme_version_id', $version->id)->where('kind', $data['kind'])->count() + 1;
        do {
            $code = sprintf('%s-%s%02d', $programme->prefix, $letter, $n++);
        } while (Activity::query()->where('programme_version_id', $version->id)->where('code', $code)->exists());
        /** @var list<string> $outcomes */
        $outcomes = array_values($data['outcomes']);
        $activity = DB::transaction(fn () => $this->catalogue->create($version, ['code' => $code] + array_intersect_key($data, array_flip(['kind', 'title', 'summary', 'minutes', 'weight', 'required'])), $outcomes, $this->actor($request)));

        return response()->json(['data' => $this->detail($activity)], 201);
    }

    public function show(Activity $activity): JsonResponse
    {
        return response()->json(['data' => $this->detail($activity)]);
    }

    public function update(Request $request, Activity $activity): JsonResponse
    {
        $input = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'], 'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:120'], 'weight' => ['sometimes', 'integer', 'min:1', 'max:10'], 'required' => ['sometimes', 'boolean'],
            'blocks' => ['sometimes', 'array', 'max:'.LessonContent::MAX_BLOCKS], 'i18n' => ['sometimes', 'array'],
        ]);

        return response()->json(['data' => $this->detail($this->studio->save($activity, $input, $this->actor($request)))]);
    }

    public function aiDraft(Request $request, Activity $activity): JsonResponse
    {
        $this->lessonOnly($activity);

        return response()->json(['data' => $this->detail($this->studio->aiDraft($activity, $this->actor($request)))]);
    }

    public function translate(Request $request, Activity $activity): JsonResponse
    {
        $this->lessonOnly($activity);
        $data = $request->validate(['locales' => ['required', 'array', 'min:1'], 'locales.*' => [Rule::in(LessonContent::LOCALES)]]);
        /** @var list<string> $locales */
        $locales = array_values($data['locales']);

        return response()->json(['data' => $this->detail($this->studio->aiTranslate($activity, $locales, $this->actor($request)))]);
    }

    public function review(Request $request, Activity $activity): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(['en', ...LessonContent::LOCALES])], 'reviewed' => ['required', 'boolean']]);

        return response()->json(['data' => $this->detail($this->studio->review($activity, $data['locale'], (bool) $data['reviewed'], $this->actor($request)))]);
    }

    public function publish(Request $request, Activity $activity): JsonResponse
    {
        $this->lessonOnly($activity);

        return response()->json(['data' => $this->detail($this->studio->publish($activity, $this->actor($request)))]);
    }

    public function upload(Request $request, ContentAssets $assets): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['image', 'audio', 'video'])], 'file' => ['required', 'file', 'max:25600']]);

        return response()->json(['data' => $assets->store($data['file'], $data['kind'], $this->actor($request))->present()], 201);
    }

    /** @return array<string, mixed> */
    private function summary(Activity $a): array
    {
        $draft = LessonStudio::draftOf($a);

        return [
            'id' => $a->id, 'kind' => $a->kind, 'code' => $a->code, 'title' => $a->title, 'summary' => $a->summary, 'minutes' => $a->minutes,
            'weight' => $a->weight, 'required' => $a->required, 'status' => $a->status, 'content_version' => $a->content_version,
            'has_draft' => $a->draft !== null, 'blocks' => count($draft['blocks']), 'outcomes' => $a->outcomeCodes(),
            'review' => array_map(fn ($r) => isset($r['by']) ? 'reviewed' : 'ai', $draft['review']),
            'published_at' => $a->published_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Activity $a): array
    {
        $draft = LessonStudio::draftOf($a);
        $assetIds = collect($draft['blocks'])->flatMap(fn ($b) => [$b['asset_id'] ?? null, $b['low_asset_id'] ?? null, $b['audio_asset_id'] ?? null])->filter()->unique()->values();

        return $this->summary($a) + [
            'draft' => $draft,
            'problems' => LessonContent::clean($draft['blocks'])[1],
            'outcome_details' => DB::table('outcomes')->where('programme_version_id', $a->programme_version_id)->whereIn('code', $a->outcomeCodes())
                ->orderBy('position')->get(['code', 'statement'])->all(),
            'scenarios' => Activity::query()->where('programme_version_id', $a->programme_version_id)->where('kind', Activity::SCENARIO)->orderBy('position')->get(['code', 'title'])->all(),
            'assets' => ContentAsset::query()->whereIn('id', $assetIds->filter(fn ($id) => preg_match('/^[0-9a-f-]{36}$/', (string) $id) === 1))->get()->mapWithKeys(fn (ContentAsset $x) => [$x->id => $x->present()]),
            'ai_ready' => (string) config('services.anthropic.key') !== '',
        ];
    }

    private function publishedVersion(Programme $programme): ProgrammeVersion
    {
        $v = ProgrammeVersion::query()->where('programme_id', $programme->id)->where('status', ProgrammeVersion::PUBLISHED)->first();
        if ($v === null) {
            throw ValidationException::withMessages(['programme' => 'Publish the programme first; lessons belong to a published version.']);
        }

        return $v;
    }

    private function lessonOnly(Activity $a): void
    {
        if ($a->kind !== Activity::LESSON) {
            throw ValidationException::withMessages(['activity' => 'Scenarios are built in Scenario Studio.']);
        }
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
