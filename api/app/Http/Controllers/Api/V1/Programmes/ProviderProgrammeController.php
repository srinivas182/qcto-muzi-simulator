<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Programmes;

use App\Http\Controllers\Controller;
use App\Models\EvidenceRequirement;
use App\Models\Programme;
use App\Models\ProviderProgrammeCheck;
use App\Models\Tenant;
use App\Models\User;
use App\Programmes\ProviderProgrammes;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** A provider's "to confirm before use" answers and its own additions to a programme. */
final class ProviderProgrammeController extends Controller
{
    public function __construct(
        private readonly ProviderProgrammes $provider,
        private readonly TenantContext $context,
    ) {}

    public function answer(Request $request, Programme $programme, string $key): JsonResponse
    {
        $data = $request->validate([
            'status' => ['present', 'nullable', Rule::in(ProviderProgrammeCheck::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000', Rule::requiredIf($request->input('status') === 'not_applicable')],
        ]);
        $status = isset($data['status']) ? (string) $data['status'] : null;
        $note = isset($data['note']) ? trim((string) $data['note']) : null;
        $this->provider->answer($programme, $key, $status, $note === '' ? null : $note, $this->actor($request));

        return $this->respond($programme);
    }

    public function addOutcome(Request $request, Programme $programme): JsonResponse
    {
        $data = $request->validate([
            'duty_area' => ['required', 'integer', 'min:1'],
            'statement' => ['required', 'string', 'min:10', 'max:500'],
            'plain_words' => ['nullable', 'string', 'max:300'],
        ]);
        $this->provider->addOutcome($programme, $this->tenant(), (int) $data['duty_area'], [
            'statement' => (string) $data['statement'], 'plain_words' => isset($data['plain_words']) ? (string) $data['plain_words'] : null,
        ], $this->actor($request));

        return $this->respond($programme, 201);
    }

    public function updateOutcome(Request $request, Programme $programme, string $id): JsonResponse
    {
        /** @var array{statement?: string, plain_words?: string|null} $data */
        $data = $request->validate([
            'statement' => ['sometimes', 'string', 'min:10', 'max:500'],
            'plain_words' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);
        $this->provider->updateOutcome($programme, $this->tenant(), $id, $data, $this->actor($request));

        return $this->respond($programme);
    }

    public function removeOutcome(Request $request, Programme $programme, string $id): JsonResponse
    {
        $this->provider->removeOutcome($programme, $this->tenant(), $id, $this->actor($request));

        return $this->respond($programme);
    }

    public function addEvidence(Request $request, Programme $programme): JsonResponse
    {
        $data = $request->validate([
            'duty_area' => ['required', 'integer', 'min:1'],
            'description' => ['required', 'string', 'min:5', 'max:500'],
            'type' => ['required', Rule::in(EvidenceRequirement::TYPES)],
            'min_count' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->provider->addEvidence($programme, $this->tenant(), (int) $data['duty_area'], [
            'description' => (string) $data['description'], 'type' => (string) $data['type'],
            'min_count' => isset($data['min_count']) ? (int) $data['min_count'] : null,
        ], $this->actor($request));

        return $this->respond($programme, 201);
    }

    public function updateEvidence(Request $request, Programme $programme, string $id): JsonResponse
    {
        /** @var array{description?: string, type?: string, min_count?: int|null} $data */
        $data = $request->validate([
            'description' => ['sometimes', 'string', 'min:5', 'max:500'],
            'type' => ['sometimes', Rule::in(EvidenceRequirement::TYPES)],
            'min_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $this->provider->updateEvidence($programme, $this->tenant(), $id, $data, $this->actor($request));

        return $this->respond($programme);
    }

    public function removeEvidence(Request $request, Programme $programme, string $id): JsonResponse
    {
        $this->provider->removeEvidence($programme, $this->tenant(), $id, $this->actor($request));

        return $this->respond($programme);
    }

    private function respond(Programme $programme, int $status = 200): JsonResponse
    {
        return response()->json(['provider' => $this->provider->view($programme, $this->tenant())], $status);
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
