<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Learn;

use App\Http\Controllers\Controller;
use App\Learning\ChecklistShares;
use App\Learning\LearnerNudges;
use App\Models\ChecklistShare;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Checklist links a learner shares with a mentor or employer; the learner's reminder setting; facilitator nudges. */
final class ShareController extends Controller
{
    public function __construct(
        private readonly ChecklistShares $shares,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request, Enrolment $enrolment): JsonResponse
    {
        $this->mine($request, $enrolment);
        $list = ChecklistShare::query()->where('enrolment_id', $enrolment->id)->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get();

        return response()->json(['data' => $list->map(fn (ChecklistShare $s) => ChecklistShares::present($s))->all()]);
    }

    public function store(Request $request, Enrolment $enrolment): JsonResponse
    {
        $this->mine($request, $enrolment);
        $data = $request->validate(['label' => ['nullable', 'string', 'max:80']]);
        $made = $this->shares->create($this->tenant(), $enrolment, $data['label'] ?? null, $this->user($request));

        return response()->json(['data' => ChecklistShares::present($made['share']) + ['url' => $made['url']]], 201);
    }

    public function destroy(Request $request, ChecklistShare $share): Response
    {
        $this->mine($request, $share->enrolment);
        $this->shares->revoke($share, $this->user($request));

        return response()->noContent();
    }

    /** Public, read-only: what a mentor or employer sees. */
    public function show(string $token): JsonResponse
    {
        $view = preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1 ? $this->shares->view($this->tenant(), $token) : null;
        if ($view === null) {
            throw new NotFoundHttpException('This link has expired or was stopped by the learner.');
        }

        return response()->json(['data' => $view])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex');
    }

    /** The learner turns reminders on or off. */
    public function reminders(Request $request): JsonResponse
    {
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];
        $learner = $this->learner($request);
        $learner->update(['reminders_off_at' => $on ? null : now()]);

        return response()->json(['data' => ['on' => $on]]);
    }

    /** A facilitator sends one reminder now (same limits as the daily run). */
    public function nudge(Enrolment $enrolment, Request $request, LearnerNudges $nudges): JsonResponse
    {
        $sent = $nudges->send($this->tenant(), $enrolment->load(['learner.user', 'programme']), $this->user($request));

        return response()->json(['data' => ['sent' => $sent]], $sent ? 200 : 422);
    }

    private function mine(Request $request, Enrolment $e): void
    {
        if ($e->learner_id !== $this->learner($request)->id) {
            throw new NotFoundHttpException;
        }
    }

    private function learner(Request $request): Learner
    {
        return Learner::query()->where('user_id', $this->user($request)->id)->first() ?? throw new NotFoundHttpException;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function tenant(): Tenant
    {
        return $this->context->tenant() ?? throw new NotFoundHttpException;
    }
}
