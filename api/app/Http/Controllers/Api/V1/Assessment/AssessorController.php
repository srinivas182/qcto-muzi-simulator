<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Assessment\Accommodations;
use App\Assessment\AssessorWorkspace;
use App\Assessment\Decisions;
use App\Http\Controllers\Controller;
use App\Models\AssessmentTask;
use App\Models\Attempt;
use App\Models\Enrolment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The assessor workspace (Sprint 5.2): the review queue, run replays and decisions. */
final class AssessorController extends Controller
{
    public function __construct(private readonly AssessorWorkspace $workspace, private readonly Decisions $decisions) {}

    public function queue(Request $request): JsonResponse
    {
        $cohort = $request->validate(['cohort' => ['sometimes', 'nullable', 'uuid']])['cohort'] ?? null;

        return response()->json(['data' => $this->workspace->queue($cohort)]);
    }

    public function attempt(Attempt $attempt): JsonResponse
    {
        return response()->json(['data' => $this->workspace->replay($attempt)])->header('Cache-Control', 'no-store');
    }

    public function task(Enrolment $enrolment, AssessmentTask $task): JsonResponse
    {
        abort_if($task->programme_version_id !== $enrolment->programme_version_id, 404);
        $enrolment->load('learner');

        return response()->json(['data' => ['enrolment_id' => $enrolment->id, 'learner' => $enrolment->learner->fullName()] + $this->workspace->taskFor($enrolment, $task)]);
    }

    public function accommodations(Enrolment $enrolment): JsonResponse
    {
        return response()->json(['data' => Accommodations::of($enrolment)]);
    }

    public function setAccommodations(Request $request, Enrolment $enrolment, Accommodations $accommodations): JsonResponse
    {
        $data = $request->validate(['extra_time' => ['required', 'integer', Rule::in(Accommodations::EXTRA_TIME)], 'read_aloud' => ['required', 'boolean']]);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $accommodations->set($enrolment, ['extra_time' => (int) $data['extra_time'], 'read_aloud' => (bool) $data['read_aloud']], $actor)]);
    }

    public function decide(Request $request, Enrolment $enrolment, AssessmentTask $task): JsonResponse
    {
        $data = $request->validate([
            'result' => ['required', Rule::in(['competent', 'not_yet'])],
            'feedback' => ['nullable', 'string', 'max:2000'],
            'links' => ['sometimes', 'array', 'max:'.Decisions::MAX_LINKS], 'links.*.type' => ['required', Rule::in(['step', 'lesson'])], 'links.*.ref' => ['required', 'string', 'max:40'],
            'attempt_id' => ['nullable', 'string', 'max:40'],
            'observation' => ['nullable', 'array', 'max:30'], 'observation.*.met' => ['required', 'boolean'], 'observation.*.note' => ['nullable', 'string', 'max:300'],
            'declaration' => ['accepted'],
            'client_id' => ['nullable', 'uuid'], 'declared_at' => ['nullable', 'date'], // offline sign-off: sent once, with the time it was signed
        ]);
        /** @var User $assessor */
        $assessor = $request->user();
        $decision = $this->decisions->decide($enrolment, $task, $data, $assessor);
        $decision->load('assessor');

        return response()->json(['data' => $this->workspace->decisionOut($decision)], 201);
    }
}
