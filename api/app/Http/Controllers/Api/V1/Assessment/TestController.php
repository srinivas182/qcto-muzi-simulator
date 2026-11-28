<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Assessment\CameraChecks;
use App\Assessment\TestSittings;
use App\Http\Controllers\Controller;
use App\Models\AssessmentTask;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\SittingPhoto;
use App\Models\Tenant;
use App\Models\TestSitting;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Learners take knowledge tests; assessors mark short answers (Sprint 5.1b). */
final class TestController extends Controller
{
    public function __construct(
        private readonly TestSittings $sittings,
        private readonly CameraChecks $camera,
        private readonly TenantContext $context,
    ) {}

    /** My summative tasks for a programme, with where I stand on each. */
    public function index(Request $request, Enrolment $enrolment): JsonResponse
    {
        $this->mine($request, $enrolment);
        $tasks = AssessmentTask::query()->where('programme_version_id', $enrolment->programme_version_id)->where('status', 'published')->orderBy('position')->get();
        $sittings = TestSitting::query()->where('enrolment_id', $enrolment->id)->orderBy('attempt')->get()->groupBy('assessment_task_id');

        return response()->json(['data' => $tasks->map(function (AssessmentTask $t) use ($sittings): array {
            $mine = $sittings->get($t->id, collect());
            $open = $mine->firstWhere('status', 'open');

            return [
                'id' => $t->id, 'code' => $t->code, 'title' => $t->title, 'component' => $t->component, 'kind' => $t->kind, 'outcomes' => $t->outcomeCodes(),
                'minutes' => $t->settings['minutes'] ?? null, 'questions' => $t->settings['pick'] ?? null, 'pass_mark' => $t->settings['pass_mark'] ?? null,
                'camera' => CameraChecks::required($this->tenant(), $t),
                'attempts_allowed' => $t->settings['attempts'] ?? null, 'attempts_used' => $mine->count(), 'open_sitting' => $open?->id,
                'waiting_for_marking' => $mine->contains('status', 'submitted'),
                'result' => $mine->contains('result', 'competent') ? 'competent' : ($mine->contains('result', 'not_yet') ? 'not_yet' : null),
                'best_score' => $mine->max('score'),
            ];
        })->all()]);
    }

    public function start(Request $request, Enrolment $enrolment, AssessmentTask $task): JsonResponse
    {
        $this->mine($request, $enrolment);
        $data = $request->validate(['locale' => ['sometimes', 'string', 'max:5'], 'device' => ['sometimes', 'nullable', 'string', 'max:40'], 'camera_consent' => ['sometimes', 'boolean']]);
        $sitting = $this->sittings->start($this->tenant(), $enrolment, $task, (string) ($data['locale'] ?? 'en'), $data['device'] ?? null, (bool) ($data['camera_consent'] ?? false), $this->user($request));

        return response()->json(['data' => TestSittings::paperFor($sitting)], 201)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, TestSitting $sitting): JsonResponse
    {
        $this->mine($request, $sitting->enrolment);
        $sitting = $this->sittings->closeIfExpired($sitting);

        return response()->json(['data' => $sitting->status === 'open' ? TestSittings::paperFor($sitting) : TestSittings::resultFor($sitting)])->header('Cache-Control', 'no-store');
    }

    /** Save answers; with submit=true, hand the test in. Safe to repeat (answers replace earlier ones). */
    public function save(Request $request, TestSitting $sitting): JsonResponse
    {
        $this->mine($request, $sitting->enrolment);
        $data = $request->validate(['answers' => ['present', 'array'], 'submit' => ['sometimes', 'boolean']]);
        $sitting = $this->sittings->save($sitting, $data['answers'], (bool) ($data['submit'] ?? false), $this->user($request));

        return response()->json(['data' => $sitting->status === 'open' ? TestSittings::paperFor($sitting) : TestSittings::resultFor($sitting)])->header('Cache-Control', 'no-store');
    }

    /** A small photo from a camera-checked sitting. */
    public function photo(Request $request, TestSitting $sitting): JsonResponse
    {
        $this->mine($request, $sitting->enrolment);
        $file = $request->validate(['photo' => ['required', 'file', 'max:60']])['photo'];
        $photo = $this->camera->photo($sitting, $file, $this->user($request));

        return response()->json(['data' => ['id' => $photo->id]], 201);
    }

    /** Assessors and moderators only: one photo of a camera-checked sitting. */
    public function showPhoto(TestSitting $sitting, SittingPhoto $photo): StreamedResponse
    {
        if ($photo->test_sitting_id !== $sitting->id) {
            throw new NotFoundHttpException;
        }

        return Storage::disk('local')->response($photo->path, null, ['Cache-Control' => 'private, no-store']);
    }

    /** Assessor view: the paper with the learner's answers, the answer key and model answers. */
    public function review(TestSitting $sitting): JsonResponse
    {
        $bank = collect((array) ($sitting->task->content['questions'] ?? []))->keyBy('id');

        return response()->json(['data' => TestSittings::resultFor($sitting) + [
            'learner' => $sitting->enrolment->learner->fullName(), 'task' => ['code' => $sitting->task->code, 'title' => $sitting->task->title],
            'camera' => $sitting->camera, 'photos' => SittingPhoto::query()->where('test_sitting_id', $sitting->id)->orderBy('taken_at')->get()->map(fn (SittingPhoto $p) => ['id' => $p->id, 'taken_at' => $p->taken_at->toIso8601String()])->all(),
            'questions' => array_map(function (array $p) use ($bank, $sitting): array {
                $q = (array) $bank->get($p['id']);
                $a = $sitting->answers[$p['id']] ?? null;
                $shown = fn (int $i) => $q['options'][$p['options'][$i] ?? -1] ?? null;

                return ['id' => $p['id'], 'type' => $q['type'], 'prompt' => $q['prompt'], 'marks' => $q['marks'], 'outcome' => $q['outcome'],
                    'answer' => $q['type'] === 'short' ? $a : (is_array($a) ? array_map($shown, $a) : (is_int($a) ? $shown($a) : null)),
                    'key' => $q['type'] === 'short' ? $q['model_answer'] : (is_array($q['answer']) ? array_map(fn (int $i) => $q['options'][$i], $q['answer']) : $q['options'][$q['answer']]),
                    'awarded' => $sitting->marks[$p['id']] ?? null];
            }, $sitting->paper),
        ]]);
    }

    public function mark(Request $request, TestSitting $sitting): JsonResponse
    {
        $data = $request->validate(['marks' => ['required', 'array']]);
        $marked = $this->sittings->mark($sitting, $data['marks'], $this->user($request));

        return response()->json(['data' => TestSittings::resultFor($marked)]);
    }

    private function mine(Request $request, Enrolment $e): void
    {
        $learner = Learner::query()->where('user_id', $this->user($request)->id)->first();
        if ($learner === null || $e->learner_id !== $learner->id) {
            throw new NotFoundHttpException;
        }
    }

    private function tenant(): Tenant
    {
        return $this->context->tenant() ?? throw new NotFoundHttpException;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
