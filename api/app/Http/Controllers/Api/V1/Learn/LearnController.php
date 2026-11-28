<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learn;

use App\Cohorts\Enrolments;
use App\Http\Controllers\Api\V1\Qcto\QctoController;
use App\Http\Controllers\Controller;
use App\Learning\AttemptSync;
use App\Learning\Progress;
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
