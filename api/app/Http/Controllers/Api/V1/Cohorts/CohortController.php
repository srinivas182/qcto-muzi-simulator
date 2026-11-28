<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cohorts;

use App\Cohorts\CohortPresenter;
use App\Cohorts\Cohorts;
use App\Cohorts\Enrolments;
use App\Http\Controllers\Controller;
use App\Models\Centre;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\LicencePool;
use App\Models\Programme;
use App\Models\Tenant;
use App\Models\User;
use App\Qcto\QctoCodes;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Provider: cohorts, their learners, and the seats they use. */
final class CohortController extends Controller
{
    public function __construct(
        private readonly Cohorts $cohorts,
        private readonly Enrolments $enrolments,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $archived = $request->boolean('archived');
        $cohorts = Cohort::query()->with(['centre', 'programme', 'version', 'facilitators'])
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->orderByDesc('starts_on')->limit(300)->get();
        $counts = Enrolment::query()->whereIn('cohort_id', $cohorts->pluck('id'))->selectRaw('cohort_id, status, count(*) as n')->groupBy('cohort_id', 'status')->get()
            ->groupBy('cohort_id')->map(fn ($rows) => $rows->pluck('n', 'status')->map(fn ($n) => (int) $n)->all());

        return response()->json(['data' => $cohorts->map(fn (Cohort $c) => CohortPresenter::summary($c, $counts->get($c->id, [])))->all()]);
    }

    /** What the "new cohort" form needs: centres, programmes this provider has seats for, and staff. */
    public function options(): JsonResponse
    {
        $programmeIds = LicencePool::query()->where('status', LicencePool::ACTIVE)->distinct()->pluck('programme_id');
        $staff = User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get()->filter(fn (User $u) => $u->hasPermission('classes.view'));

        return response()->json(['data' => [
            'centres' => Centre::query()->orderBy('name')->get(['id', 'name']),
            'programmes' => Programme::query()->whereIn('id', $programmeIds)->orderBy('position')->get(['id', 'code', 'title'])
                ->map(fn (Programme $p) => ['id' => $p->id, 'code' => $p->code, 'title' => $p->title, 'free_seats' => $this->freeSeats($p->id)]),
            'staff' => $staff->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->values(),
        ]]);
    }

    public function show(Cohort $cohort): JsonResponse
    {
        $cohort->load(['centre', 'programme', 'version', 'facilitators']);
        $enrolments = Enrolment::query()->with('learner')->where('cohort_id', $cohort->id)->get()
            ->sortBy(fn (Enrolment $e) => [in_array($e->status, Enrolment::CURRENT, true) ? 0 : 1, $e->learner->surname, $e->learner->first_names])->values();
        $counts = $enrolments->countBy('status')->all();

        return response()->json(['data' => CohortPresenter::summary($cohort, $counts) + [
            'join_code' => $cohort->join_code,
            'free_seats' => $this->freeSeats($cohort->programme_id),
            'enrolments' => $enrolments->map(fn (Enrolment $e) => CohortPresenter::enrolment($e, $cohort))->all(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules() + ['programme_id' => ['required', 'uuid', Rule::exists('programmes', 'id')]]);
        $cohort = $this->cohorts->create($input, $this->actor($request));

        return $this->show($cohort)->setStatusCode(201);
    }

    public function update(Request $request, Cohort $cohort): JsonResponse
    {
        $input = $request->validate($this->rules(true) + ['join_open' => ['sometimes', 'boolean'], 'qcto_submitted' => ['sometimes', 'boolean'],
            'qcto_qualification_id' => ['sometimes', 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/'], 'readiness_type' => ['sometimes', Rule::in(array_map('strval', array_keys(QctoCodes::READINESS)))]]);
        $this->cohorts->update($cohort, $input, $this->actor($request));

        return $this->show($cohort->refresh());
    }

    public function newCode(Request $request, Cohort $cohort): JsonResponse
    {
        return $this->show($this->cohorts->newJoinCode($cohort, $this->actor($request)));
    }

    public function archive(Request $request, Cohort $cohort): JsonResponse
    {
        return $this->show($this->cohorts->archive($cohort, $this->actor($request)));
    }

    public function enrol(Request $request, Cohort $cohort): JsonResponse
    {
        $data = $request->validate(['learner_ids' => ['required', 'array', 'min:1', 'max:500'], 'learner_ids.*' => ['uuid']]);
        /** @var list<string> $ids */
        $ids = array_values($data['learner_ids']);
        $result = $this->enrolments->enrol($this->tenant(), $cohort, $ids, $this->actor($request));

        return response()->json(['data' => ['enrolled' => count($result['enrolled']), 'skipped' => $result['skipped']]]);
    }

    public function withdraw(Request $request, Enrolment $enrolment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);

        return response()->json(['data' => CohortPresenter::enrolment($this->enrolments->withdraw($enrolment, $data['reason'], $this->actor($request)))]);
    }

    public function transfer(Request $request, Enrolment $enrolment): JsonResponse
    {
        $data = $request->validate(['cohort_id' => ['required', 'uuid']]);
        $to = Cohort::query()->findOrFail($data['cohort_id']);

        return response()->json(['data' => CohortPresenter::enrolment($this->enrolments->transfer($enrolment, $to, $this->actor($request))->refresh())]);
    }

    /** Learner: join a cohort with the code from the QR poster. */
    public function join(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);
        $enrolment = $this->cohorts->join($this->tenant(), $data['code'], $this->actor($request));

        return response()->json(['data' => ['enrolment_id' => $enrolment->id, 'cohort' => $enrolment->cohort->name]]);
    }

    private function freeSeats(string $programmeId): int
    {
        return (int) $this->enrolments->openPools($programmeId)->sum(fn (LicencePool $p) => max(0, $p->seats - Enrolment::query()->where('licence_pool_id', $p->id)->where('holds_seat', true)->count()));
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:120'],
            'centre_id' => [$req, 'uuid', function (string $attr, mixed $value, \Closure $fail): void {
                if (! Centre::query()->whereKey($value)->exists()) {
                    $fail('Choose one of your centres.');
                }
            }],
            'starts_on' => [$req, 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'qcto_type' => ['sometimes', Rule::in(Cohort::QCTO_TYPES)],
            'facilitator_ids' => ['sometimes', 'array', 'max:20'],
            'facilitator_ids.*' => ['uuid'],
        ];
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return $this->context->tenant();
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
