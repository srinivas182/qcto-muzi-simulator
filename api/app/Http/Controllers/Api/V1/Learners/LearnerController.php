<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learners;

use App\Http\Controllers\Controller;
use App\Learners\LearnerDirectory;
use App\Models\Learner;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Provider: learners (list, add, import, change, invite, deactivate, reveal ID). */
final class LearnerController extends Controller
{
    public function __construct(
        private readonly LearnerDirectory $directory,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'status' => ['nullable', Rule::in([Learner::INVITED, Learner::ACTIVE, Learner::INACTIVE])]]);
        $q = trim((string) ($filters['q'] ?? ''));
        $learners = Learner::query()
            ->when($filters['status'] ?? null, fn ($query, $s) => $query->where('status', $s))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('first_names', 'ilike', "%{$q}%")->orWhere('surname', 'ilike', "%{$q}%")
                ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $q).'%')->orWhere('email', 'ilike', "%{$q}%")
                ->orWhere('id_last4', $q)))
            ->orderBy('surname')->orderBy('first_names')->limit(500)->get();
        $counts = Learner::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json(['data' => $learners->map(fn (Learner $l) => LearnerDirectory::present($l))->all(), 'counts' => $counts]);
    }

    public function show(Learner $learner): JsonResponse
    {
        return response()->json(['data' => LearnerDirectory::present($learner)]);
    }

    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules() + ['invite' => ['sometimes', 'boolean']]);
        $learner = $this->directory->create($this->tenant(), $input, $this->actor($request), (bool) ($input['invite'] ?? false));

        return response()->json(['data' => LearnerDirectory::present($learner)], 201);
    }

    public function update(Request $request, Learner $learner): JsonResponse
    {
        $input = $request->validate($this->rules(true) + ['qcto_learner_number' => ['sometimes', 'nullable', 'string', 'max:40']]);

        return response()->json(['data' => LearnerDirectory::present($this->directory->update($learner, $input, $this->actor($request)))]);
    }

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:'.LearnerDirectory::MAX_IMPORT_ROWS],
            'rows.*' => ['array'],
            'dry_run' => ['required', 'boolean'],
            'invite' => ['sometimes', 'boolean'],
        ]);
        /** @var list<array<string, mixed>> $rows */
        $rows = array_values(array_map(fn ($r) => array_map(fn ($v) => is_scalar($v) ? (string) $v : '', (array) $r), (array) $data['rows']));

        return response()->json(['data' => $this->directory->import($this->tenant(), $rows, (bool) $data['dry_run'], (bool) ($data['invite'] ?? false), $this->actor($request))]);
    }

    public function invite(Request $request, Learner $learner): JsonResponse
    {
        return response()->json(['data' => $this->directory->invite($this->tenant(), $learner, $this->actor($request))]);
    }

    public function status(Request $request, Learner $learner): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        return response()->json(['data' => LearnerDirectory::present($this->directory->setActive($learner, (bool) $data['active'], $this->actor($request)))]);
    }

    public function revealId(Request $request, Learner $learner): JsonResponse
    {
        return response()->json(['data' => ['id_number' => $this->directory->reveal($learner, $this->actor($request))]])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        return [
            'first_names' => [$req, 'string', 'max:120'],
            'surname' => [$req, 'string', 'max:120'],
            'id_type' => [$updating ? 'sometimes' : 'nullable', Rule::in(['sa_id', 'passport'])],
            'id_number' => [$updating ? 'sometimes' : 'required', 'nullable', 'string', 'max:30'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', Rule::in(['female', 'male'])],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'string', 'max:190'],
            'home_language' => ['sometimes', 'nullable', Rule::in(Learner::LANGUAGES)],
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
