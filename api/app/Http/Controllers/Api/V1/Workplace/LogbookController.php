<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Workplace;

use App\Http\Controllers\Controller;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LogbookEntry;
use App\Models\User;
use App\Workplace\Logbook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The workplace logbook (Sprint 5.3): learners check in daily; staff set the placement and see the logbook. */
final class LogbookController extends Controller
{
    public function __construct(private readonly Logbook $logbook) {}

    /** Learner: my workplace, my days and the outcomes I can log against. */
    public function mine(Request $request, Enrolment $enrolment): JsonResponse
    {
        $this->own($request, $enrolment);

        return response()->json(['data' => $this->view($enrolment, false)]);
    }

    public function checkIn(Request $request, Enrolment $enrolment): JsonResponse
    {
        $this->own($request, $enrolment);
        $data = $request->validate([
            'client_id' => ['required', 'uuid'], 'day' => ['required', 'date_format:Y-m-d'], 'hours' => ['required', 'numeric'],
            'outcomes' => ['sometimes', 'array', 'max:20'], 'outcomes.*' => ['string', 'max:20'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $entry = $this->logbook->checkIn($enrolment, $data, $this->user($request));

        return response()->json(['data' => Logbook::entryOut($entry)], 201);
    }

    public function photo(Request $request, LogbookEntry $entry): JsonResponse
    {
        $this->own($request, Enrolment::query()->findOrFail($entry->enrolment_id));
        $file = $request->validate(['photo' => ['required', 'file', 'max:150']])['photo'];

        return response()->json(['data' => Logbook::entryOut($this->logbook->photo($entry, $file))]);
    }

    /** Staff: the placement and the logbook of one learner. */
    public function show(Enrolment $enrolment): JsonResponse
    {
        return response()->json(['data' => $this->view($enrolment, true)]);
    }

    public function place(Request $request, Enrolment $enrolment): JsonResponse
    {
        $data = $request->validate([
            'employer' => ['required', 'string', 'max:120'], 'site' => ['nullable', 'string', 'max:160'], 'mentor_name' => ['required', 'string', 'max:80'],
            'mentor_phone' => ['required', 'string', 'max:20'], 'confirm_by' => ['required', Rule::in(['link', 'account'])], 'mentor_user_id' => ['nullable', 'uuid'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);
        if (($data['mentor_user_id'] ?? null) !== null && ! User::query()->whereKey($data['mentor_user_id'])->whereHas('roles', fn ($q) => $q->where('key', 'assessor'))->exists()) {
            throw ValidationException::withMessages(['mentor_user_id' => 'Choose a mentor with an assessor/mentor account at this provider.']);
        }
        $w = $this->logbook->place($enrolment, $data, $this->user($request));

        return response()->json(['data' => Logbook::workplaceOut($w, true)]);
    }

    /** Staff (and the learner, for their own day): a logbook photo. */
    public function showPhoto(Request $request, LogbookEntry $entry): StreamedResponse
    {
        $user = $this->user($request);
        $learner = Learner::query()->where('user_id', $user->id)->first();
        $own = $learner !== null && Enrolment::query()->whereKey($entry->enrolment_id)->where('learner_id', $learner->id)->exists();
        if (($own === false && ! $user->hasPermission('learners.view')) || $entry->photo_path === null) {
            throw new NotFoundHttpException;
        }

        return Storage::disk('local')->response($entry->photo_path, null, ['Cache-Control' => 'private, no-store']);
    }

    /** @return array<string, mixed> */
    private function view(Enrolment $e, bool $staff): array
    {
        return [
            'workplace' => Logbook::workplaceOut(Logbook::current($e), $staff),
            'outcomes' => Logbook::outcomes($e),
            'summary' => Logbook::summary($e),
            'entries' => LogbookEntry::query()->where('enrolment_id', $e->id)->orderByDesc('day')->limit(120)->get()->map(fn (LogbookEntry $x) => Logbook::entryOut($x))->all(),
            'late_days' => Logbook::LATE_DAYS,
        ];
    }

    private function own(Request $request, Enrolment $e): void
    {
        $learner = Learner::query()->where('user_id', $this->user($request)->id)->first();
        if ($learner === null || $e->learner_id !== $learner->id) {
            throw new NotFoundHttpException;
        }
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }
}
