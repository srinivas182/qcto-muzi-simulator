<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Programmes;

use App\Http\Controllers\Controller;
use App\Models\EvidenceRequirement;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\User;
use App\Programmes\ProgrammePresenter;
use App\Programmes\ProgrammeStudio;
use App\Programmes\VersionComparison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HQ Programme Studio: edit a draft, send it for review, approve or send back, publish, compare versions. */
final class ProgrammeStudioController extends Controller
{
    public function __construct(private readonly ProgrammeStudio $studio) {}

    public function start(Request $request, Programme $programme): JsonResponse
    {
        return $this->respond($this->studio->startDraft($programme, $this->actor($request)), 201);
    }

    public function update(Request $request, Programme $programme): JsonResponse
    {
        $list = ['sometimes', 'array', 'max:40'];
        $item = ['string', 'min:2', 'max:500'];
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'summary' => ['sometimes', 'string', 'min:10', 'max:500'],
            'purpose' => ['sometimes', 'string', 'min:10', 'max:3000'],
            'occupations' => $list, 'occupations.*' => $item,
            'workplace_requirements' => $list, 'workplace_requirements.*' => $item,
            'tools' => $list, 'tools.*' => $item,
            'to_confirm' => $list, 'to_confirm.*' => $item,
            'change_notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return $this->respond($this->studio->updateDraft($programme, $data, $this->actor($request)));
    }

    public function discard(Request $request, Programme $programme): JsonResponse
    {
        $this->studio->discard($programme, $this->actor($request));

        return response()->json(['message' => 'Draft discarded.']);
    }

    public function addDutyArea(Request $request, Programme $programme): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:3', 'max:200']]);
        $this->studio->addDutyArea($programme, (string) $data['title'], $this->actor($request));

        return $this->draftResponse($programme, 201);
    }

    public function updateDutyArea(Request $request, Programme $programme, int $number): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'min:3', 'max:200']]);
        $this->studio->updateDutyArea($programme, $number, (string) $data['title'], $this->actor($request));

        return $this->draftResponse($programme);
    }

    public function removeDutyArea(Request $request, Programme $programme, int $number): JsonResponse
    {
        $this->studio->removeDutyArea($programme, $number, $this->actor($request));

        return $this->draftResponse($programme);
    }

    public function addOutcome(Request $request, Programme $programme, int $number): JsonResponse
    {
        $data = $request->validate(['statement' => ['required', 'string', 'min:10', 'max:1000'], 'plain_words' => ['nullable', 'string', 'max:255']]);
        $this->studio->addOutcome($programme, $number, (string) $data['statement'], isset($data['plain_words']) ? (string) $data['plain_words'] : null, $this->actor($request));

        return $this->draftResponse($programme, 201);
    }

    public function updateOutcome(Request $request, Programme $programme, string $code): JsonResponse
    {
        /** @var array{statement?: string, plain_words?: string|null} $data */
        $data = $request->validate(['statement' => ['sometimes', 'string', 'min:10', 'max:1000'], 'plain_words' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $this->studio->updateOutcome($programme, $code, $data, $this->actor($request));

        return $this->draftResponse($programme);
    }

    public function removeOutcome(Request $request, Programme $programme, string $code): JsonResponse
    {
        $this->studio->removeOutcome($programme, $code, $this->actor($request));

        return $this->draftResponse($programme);
    }

    public function addEvidence(Request $request, Programme $programme, int $number): JsonResponse
    {
        /** @var array{description: string, type: string, min_count?: int} $data */
        $data = $request->validate($this->evidenceRules(true));
        $this->studio->addEvidence($programme, $number, $data, $this->actor($request));

        return $this->draftResponse($programme, 201);
    }

    public function updateEvidence(Request $request, Programme $programme, string $id): JsonResponse
    {
        /** @var array{description?: string, type?: string, min_count?: int} $data */
        $data = $request->validate($this->evidenceRules(false));
        $this->studio->updateEvidence($programme, $id, $data, $this->actor($request));

        return $this->draftResponse($programme);
    }

    public function removeEvidence(Request $request, Programme $programme, string $id): JsonResponse
    {
        $this->studio->removeEvidence($programme, $id, $this->actor($request));

        return $this->draftResponse($programme);
    }

    public function submit(Request $request, Programme $programme): JsonResponse
    {
        $data = $request->validate(['change_notes' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->respond($this->studio->submit($programme, (string) $data['change_notes'], $this->actor($request)));
    }

    public function approve(Request $request, Programme $programme): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        return $this->respond($this->studio->approve($programme, isset($data['comment']) ? (string) $data['comment'] : null, $this->actor($request)));
    }

    public function requestChanges(Request $request, Programme $programme): JsonResponse
    {
        $data = $request->validate(['comment' => ['required', 'string', 'min:10', 'max:2000']]);

        return $this->respond($this->studio->requestChanges($programme, (string) $data['comment'], $this->actor($request)));
    }

    public function publish(Request $request, Programme $programme): JsonResponse
    {
        return $this->respond($this->studio->publish($programme, $this->actor($request)));
    }

    public function compare(Request $request, Programme $programme): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'integer', 'min:1'], 'to' => ['required', 'integer', 'min:1']]);
        $find = fn (int $n) => ProgrammeVersion::query()->where('programme_id', $programme->id)->where('number', $n)->firstOrFail();

        return response()->json(['data' => [
            'from' => (int) $data['from'],
            'to' => (int) $data['to'],
            'changes' => VersionComparison::compare($find((int) $data['from']), $find((int) $data['to'])),
        ]]);
    }

    /** @return array<string, list<mixed>> */
    private function evidenceRules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'description' => [$req, 'string', 'min:5', 'max:500'],
            'type' => [$req, Rule::in(EvidenceRequirement::TYPES)],
            'min_count' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    private function draftResponse(Programme $programme, int $status = 200): JsonResponse
    {
        return $this->respond($this->studio->draft($programme, editable: false), $status);
    }

    private function respond(ProgrammeVersion $version, int $status = 200): JsonResponse
    {
        return response()->json(['data' => ProgrammePresenter::studio($version->fresh() ?? $version)], $status);
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
