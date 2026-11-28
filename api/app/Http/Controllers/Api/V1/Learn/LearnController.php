<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learn;

use App\Cohorts\Enrolments;
use App\Http\Controllers\Api\V1\Qcto\QctoController;
use App\Http\Controllers\Controller;
use App\Learning\AttemptSync;
use App\Learning\ClassMode;
use App\Learning\CohortProgress;
use App\Learning\LearnContent;
use App\Learning\Progress;
use App\Learning\SimCoach;
use App\Models\Activity;
use App\Models\Attempt;
use App\Models\ClassSession;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LearnerRating;
use App\Models\User;
use App\Qcto\QctoDetails;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
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
            'learner' => $learner === null ? null : ['name' => $learner->fullName(), 'qcto_missing' => count(QctoDetails::missing($learner)), 'reminders' => $learner->reminders_off_at === null],
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
                    'class' => ($live = ClassSession::liveFor($e->cohort_id)) === null ? null : ClassMode::present($live), // "Your class is doing this now"
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
        $this->mineAndPublished($request, $enrolment, $activity, Activity::LESSON);

        return response()->json(['data' => LearnContent::lesson($activity, LearnContent::locale($request->query('locale')))])->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * A simulator scenario in the learner's language, with where each step's lesson lives (for the debrief)
     * and the learner's best result so far in practice and in real-job mode.
     */
    public function scenario(Request $request, Enrolment $enrolment, Activity $activity): JsonResponse
    {
        $this->mineAndPublished($request, $enrolment, $activity, Activity::SCENARIO);

        return response()->json(['data' => LearnContent::scenario($enrolment, $activity, LearnContent::locale($request->query('locale')))])->header('Cache-Control', 'private, no-cache');
    }

    /**
     * The whole programme for offline use (download on Wi-Fi). Send If-None-Match with the pack's version to
     * learn whether anything changed: 304 means the pack on the phone is up to date.
     */
    public function pack(Request $request, Enrolment $enrolment, TenantContext $context): JsonResponse|Response
    {
        if ($enrolment->learner_id !== $this->learner($request)->id) {
            throw new NotFoundHttpException;
        }
        $pack = LearnContent::pack($context->tenant() ?? throw new NotFoundHttpException, $enrolment, LearnContent::locale($request->query('locale')));
        $etag = '"'.$pack['version'].'"';
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->noContent(304)->header('ETag', $etag);
        }

        // Pilot measure: data used per learner (what the device downloads; files it already has are skipped).
        $enrolment->increment('pack_downloads', 1, ['pack_bytes' => $enrolment->pack_bytes + (int) $pack['bytes']]);

        return response()->json(['data' => $pack])->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
    }

    /** The AI coach in a simulator (practice mode): "why" after a mistake, "help" when stuck. */
    public function coach(Request $request, Enrolment $enrolment, Activity $activity, SimCoach $coach): JsonResponse
    {
        $this->mineAndPublished($request, $enrolment, $activity, Activity::SCENARIO);
        $data = $request->validate(['step' => ['required', 'string', 'max:12'], 'kind' => ['required', 'in:why,help'], 'locale' => ['sometimes', 'string'], 'variant' => ['nullable', 'string', 'max:12']]);

        return response()->json(['data' => $coach->ask($activity, $data['step'], $data['kind'], LearnContent::locale($data['locale'] ?? 'en'), $data['variant'] ?? null)]);
    }

    /** The learner's 3-question rating of a simulator they have finished (1–5 each). */
    public function rate(Request $request, Enrolment $enrolment, Activity $activity): JsonResponse
    {
        $this->mineAndPublished($request, $enrolment, $activity, Activity::SCENARIO);
        $data = $request->validate(['easy' => ['required', 'integer', 'between:1,5'], 'real' => ['required', 'integer', 'between:1,5'], 'ready' => ['required', 'integer', 'between:1,5']]);
        if (! Attempt::query()->where('enrolment_id', $enrolment->id)->where('activity_id', $activity->id)->whereNotNull('finished_at')->exists()) {
            throw ValidationException::withMessages(['rating' => 'Finish the simulator first.']);
        }
        LearnerRating::query()->updateOrCreate(['enrolment_id' => $enrolment->id, 'activity_id' => $activity->id], $data);

        return response()->json(['data' => $data], 201);
    }

    private function mineAndPublished(Request $request, Enrolment $e, Activity $a, string $kind): void
    {
        if ($e->learner_id !== $this->learner($request)->id || $a->programme_version_id !== $e->programme_version_id || $a->status !== Activity::PUBLISHED || $a->kind !== $kind) {
            throw new NotFoundHttpException;
        }
    }

    /** My job-ready checklist: each QCTO outcome, shown / practised / not yet. */
    public function checklist(Request $request, Enrolment $enrolment, CohortProgress $progress): JsonResponse
    {
        if ($enrolment->learner_id !== $this->learner($request)->id) {
            throw new NotFoundHttpException;
        }

        return response()->json(['data' => ['areas' => $progress->checklist($enrolment)]])->header('Cache-Control', 'private, no-cache');
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
