<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Programmes;

use App\Http\Controllers\Controller;
use App\Models\OutcomeCoverage;
use App\Models\Programme;
use App\Models\User;
use App\Programmes\CoverageMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** HQ coverage map: which simulator or course covers each outcome, and readiness per programme. */
final class CoverageController extends Controller
{
    public function __construct(private readonly CoverageMap $coverage) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->coverage->overview()]);
    }

    public function show(Programme $programme): JsonResponse
    {
        return response()->json(['data' => $this->coverage->forProgramme($programme)]);
    }

    public function update(Request $request, Programme $programme, string $code): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['required', Rule::in(OutcomeCoverage::CHANNELS)],
            'status' => ['present', 'nullable', Rule::in(OutcomeCoverage::STATUSES)],
            'title' => ['nullable', 'string', 'max:200'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $title = isset($data['title']) ? trim((string) $data['title']) : '';
        $this->coverage->set($programme, $code, (string) $data['channel'], isset($data['status']) ? (string) $data['status'] : null, $title === '' ? null : $title, $actor);

        return $this->show($programme);
    }
}
