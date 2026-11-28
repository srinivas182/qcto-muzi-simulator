<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learn;

use App\Cohorts\Enrolments;
use App\Http\Controllers\Api\V1\Qcto\QctoController;
use App\Http\Controllers\Controller;
use App\Learning\AttemptSync;
use App\Learning\LessonContent;
use App\Learning\Progress;
use App\Learning\ScenarioContent;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\ContentAsset;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\User;
use App\Qcto\QctoDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Learner: their programmes, starting a programme, and their own QCTO details. */
final class LearnController extends Controller
{
    public function __construct(private readonly QctoDetails $details) {}

    public function home(Request $request): JsonResponse
    {
        $learner = $this->learner($request, required: false);
        $enrolments = $learner === null ? collect() : Enrolment::query()->with(['cohort.centre', 'cohort.facilitators', 'cohort.programme', 'cohort.version'])
            ->where('learner_id', $learner->id)->whereIn('status', [Enrolment::ENROLLED, Enrolment::ACTIVE, Enrolment::COMPLETED])
            ->orderByDesc('seat_taken_at')->get();

        return response()->json(['data' => [
            'learner' => $learner === null ? null : ['name' => $learner->fullName(), 'qcto_missing' => count(QctoDetails::missing($learner))],
            'enrolments' => $enrolments->map(function (Enrolment $e): array {
                $c = $e->cohort;
                $end = $c->ends_on;

                return [
                    'id' => $e->id,
                    'status' => $e->status,
                    'progress' => $e->progress,
                    'started' => $e->started_at !== null,
                    'programme' => ['code' => $c->programme->code, 'title' => $c->programme->title, 'prefix' => $c->programme->prefix],
                    'version' => $c->version->number,
                    'cohort' => ['name' => $c->name, 'centre' => $c->centre->name, 'facilitators' => $c->facilitators->pluck('name')->values()->all()],
                    'starts_on' => $c->starts_on->toDateString(),
                    'ends_on' => $end?->toDateString(),
                    'days_left' => $end === null ? null : max(0, (int) today()->diffInDays($end, false)),
                    'seat_returns_on' => $e->started_at === null && $e->status === Enrolment::ENROLLED ? $e->noShowDate($c)->toDateString() : null,
                    'next' => Progress::next($e), // the "Continue" card: the first required activity not done yet
                ];
            })->all(),
        ]]);
    }

    /** The learner opened their programme. From now on the seat stays theirs (no-show rule no longer applies). */
    public function start(Request $request, Enrolment $enrolment): JsonResponse
    {
        $learner = $this->learner($request);
        if ($enrolment->learner_id !== $learner->id) {
            throw new NotFoundHttpException;
        }
        Enrolments::markStarted($enrolment);

        return response()->json(['data' => ['status' => $enrolment->refresh()->status]]);
    }

    /** The activities of one of my programmes, with how far I am on each. */
    public function activities(Request $request, Enrolment $enrolment): JsonResponse
    {
        if ($enrolment->learner_id !== $this->learner($request)->id) {
            throw new NotFoundHttpException;
        }

        return response()->json(['data' => ['progress' => $enrolment->progress, 'activities' => Progress::board($enrolment)]]);
    }

    /**
     * A published lesson for the learner, in their language (reviewed translations only; otherwise English),
     * with the pictures, audio and simulator titles it uses.
     */
    public function lesson(Request $request, Enrolment $enrolment, Activity $activity): JsonResponse
    {
        if ($enrolment->learner_id !== $this->learner($request)->id || $activity->programme_version_id !== $enrolment->programme_version_id
            || $activity->status !== Activity::PUBLISHED || $activity->kind !== Activity::LESSON) {
            throw new NotFoundHttpException;
        }
        $locale = in_array($request->query('locale'), LessonContent::LOCALES, true) ? (string) $request->query('locale') : 'en';
        $blocks = LessonContent::localise($activity->content, $locale);
        $assetIds = collect($blocks)->flatMap(fn ($b) => [$b['asset_id'] ?? null, $b['low_asset_id'] ?? null, $b['audio_asset_id'] ?? null])->filter()->unique();
        $reviewed = $locale === 'en' || isset($activity->content['review'][$locale]['by']);

        return response()->json(['data' => [
            'id' => $activity->id, 'code' => $activity->code, 'title' => $activity->title, 'minutes' => $activity->minutes,
            'content_version' => $activity->content_version, 'locale' => $reviewed ? $locale : 'en', 'blocks' => $blocks,
            'assets' => ContentAsset::query()->whereIn('id', $assetIds->filter(fn ($id) => preg_match('/^[0-9a-f-]{36}$/', (string) $id) === 1))->get()->mapWithKeys(fn (ContentAsset $a) => [$a->id => $a->present()]),
            'scenarios' => Activity::query()->where('programme_version_id', $activity->programme_version_id)->where('kind', Activity::SCENARIO)->where('status', Activity::PUBLISHED)->get(['id', 'code', 'title'])->keyBy('code'),
        ]])->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * A simulator scenario in the learner's language, with where each step's lesson lives (for the debrief)
     * and the learner's best result so far in practice and in real-job mode.
     */
    public function scenario(Request $request, Enrolment $enrolment, Activity $activity): JsonResponse
    {
        if ($enrolment->learner_id !== $this->learner($request)->id || $activity->programme_version_id !== $enrolment->programme_version_id
            || $activity->status !== Activity::PUBLISHED || $activity->kind !== Activity::SCENARIO) {
            throw new NotFoundHttpException;
        }
        $locale = in_array($request->query('locale'), LessonContent::LOCALES, true) ? (string) $request->query('locale') : 'en';
        $content = ScenarioContent::localise($activity->content ?? [], $locale);
        $best = Attempt::query()->where('enrolment_id', $enrolment->id)->where('activity_id', $activity->id)->whereNotNull('finished_at')
            ->get(['mode', 'score', 'result'])->groupBy('mode')
            ->map(fn ($list) => ['best' => (int) $list->max('score'), 'tries' => $list->count(), 'passed' => $list->contains(fn (Attempt $a) => (bool) ($a->result['passed'] ?? false))]);

        return response()->json(['data' => [
            'id' => $activity->id, 'code' => $activity->code, 'title' => $activity->title, 'summary' => $activity->summary, 'minutes' => $activity->minutes,
            'content_version' => $activity->content_version, 'locale' => $locale === 'en' || isset($activity->content['review'][$locale]['by']) ? $locale : 'en',
            'content' => $content,
            'lessons' => Activity::query()->where('programme_version_id', $activity->programme_version_id)->where('kind', Activity::LESSON)->where('status', Activity::PUBLISHED)->pluck('id', 'code'),
            'results' => ['practice' => $best->get('practice'), 'job' => $best->get('job')],
        ]])->header('Cache-Control', 'private, no-cache');
    }

    /** Attempts from the device, online or queued offline. Safe to send again; see AttemptSync. */
    public function sync(Request $request, AttemptSync $sync): JsonResponse
    {
        $data = $request->validate(['attempts' => ['required', 'array', 'min:1', 'max:'.AttemptSync::MAX_BATCH], 'attempts.*' => ['array']]);
        /** @var list<array<string, mixed>> $items */
        $items = array_values($data['attempts']);

        return response()->json(['data' => $sync->sync($this->learner($request), $items)]);
    }

    public function qcto(Request $request): JsonResponse
    {
        // The learner's own details, including equity and disability.
        return response()->json(['data' => QctoDetails::present($this->learner($request), true)])->header('Cache-Control', 'no-store');
    }

    public function updateQcto(Request $request): JsonResponse
    {
        $input = $request->validate(QctoController::rules());
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => QctoDetails::present($this->details->update($this->learner($request), $input, $user), true)])->header('Cache-Control', 'no-store');
    }

    /** @return ($required is true ? Learner : Learner|null) */
    private function learner(Request $request, bool $required = true): ?Learner
    {
        /** @var User $user */
        $user = $request->user();
        $learner = Learner::query()->where('user_id', $user->id)->first();
        if ($learner === null && $required) {
            throw new NotFoundHttpException('Your provider has not added you to its learner register.');
        }

        return $learner;
    }
}
