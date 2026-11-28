<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Studio;

use App\Assessment\AssessmentPlans;
use App\Assessment\QuestionBank;
use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\AssessmentTask;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** HQ: a programme's assessment plan (Sprint 5.1a): summative tasks, outcome coverage and question banks. */
final class AssessmentPlanController extends Controller
{
    public function __construct(private readonly AssessmentPlans $plans) {}

    public function index(Programme $programme): JsonResponse
    {
        return response()->json(['data' => $this->plans->plan($this->published($programme))]);
    }

    public function store(Request $request, Programme $programme): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'component' => ['required', Rule::in(AssessmentTask::COMPONENTS)],
            'kind' => ['required', Rule::in(['test', 'simulator', 'observation', 'logbook'])], 'activity_id' => ['nullable', 'uuid'],
            'required' => ['sometimes', 'boolean'], 'outcomes' => ['required', 'array', 'min:1', 'max:40'], 'outcomes.*' => ['string', 'max:20'],
        ]);
        /** @var list<string> $outcomes */
        $outcomes = array_values($data['outcomes']);
        $task = $this->plans->create($this->published($programme), $data, $outcomes, $this->actor($request));

        return response()->json(['data' => $this->detail($task)], 201);
    }

    public function show(AssessmentTask $task): JsonResponse
    {
        return response()->json(['data' => $this->detail($task)]);
    }

    public function update(Request $request, AssessmentTask $task): JsonResponse
    {
        $input = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'], 'required' => ['sometimes', 'boolean'],
            'questions' => ['sometimes', 'array', 'max:'.QuestionBank::MAX_QUESTIONS], 'settings' => ['sometimes', 'array'], 'i18n' => ['sometimes', 'array'],
        ]);

        return response()->json(['data' => $this->detail($this->plans->save($task, $input, $this->actor($request)))]);
    }

    public function aiDraft(Request $request, AssessmentTask $task): JsonResponse
    {
        $count = (int) $request->validate(['count' => ['sometimes', 'integer', 'min:1', 'max:30']])['count'] ?: 10;

        return response()->json(['data' => $this->detail($this->plans->aiDraft($task, $count, $this->actor($request)))]);
    }

    public function translate(Request $request, AssessmentTask $task): JsonResponse
    {
        $data = $request->validate(['locales' => ['required', 'array', 'min:1'], 'locales.*' => [Rule::in(QuestionBank::LOCALES)]]);
        /** @var list<string> $locales */
        $locales = array_values($data['locales']);

        return response()->json(['data' => $this->detail($this->plans->aiTranslate($task, $locales, $this->actor($request)))]);
    }

    public function review(Request $request, AssessmentTask $task): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(['en', ...QuestionBank::LOCALES])], 'reviewed' => ['required', 'boolean']]);

        return response()->json(['data' => $this->detail($this->plans->review($task, $data['locale'], (bool) $data['reviewed'], $this->actor($request)))]);
    }

    public function publish(Request $request, AssessmentTask $task): JsonResponse
    {
        return response()->json(['data' => $this->detail($this->plans->publish($task, $this->actor($request)))]);
    }

    /** @return array<string, mixed> */
    private function detail(AssessmentTask $t): array
    {
        return AssessmentPlans::summary($t) + [
            'draft' => AssessmentPlans::draftOf($t), 'problems' => AssessmentPlans::problems($t),
            'outcome_details' => DB::table('outcomes')->where('programme_version_id', $t->programme_version_id)->whereIn('code', $t->outcomeCodes())->orderBy('position')->get(['code', 'statement'])->all(),
            'simulators' => Activity::query()->where('programme_version_id', $t->programme_version_id)->where('kind', Activity::SCENARIO)->orderBy('position')->get(['id', 'code', 'title'])->all(),
            'ai_ready' => (string) config('services.anthropic.key') !== '',
        ];
    }

    private function published(Programme $programme): ProgrammeVersion
    {
        return ProgrammeVersion::query()->where('programme_id', $programme->id)->where('status', ProgrammeVersion::PUBLISHED)->first()
            ?? throw ValidationException::withMessages(['programme' => 'Publish the programme first; the assessment plan belongs to a published version.']);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
