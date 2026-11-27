<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Programmes;

use App\Http\Controllers\Controller;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Programmes\ProgrammePresenter;
use Illuminate\Http\JsonResponse;

/** HQ view of the catalogue: every programme and every version, drafts included. */
final class PlatformProgrammeController extends Controller
{
    public function index(): JsonResponse
    {
        $programmes = Programme::query()
            ->with([
                'published' => fn ($q) => $q->withCount(['dutyAreas', 'outcomes', 'evidence']),
                'openDraft:id,programme_id,number,status',
            ])
            ->orderBy('position')
            ->get()
            ->map(fn (Programme $p) => ProgrammePresenter::summary($p, $p->published) + [
                'status' => $p->status,
                'draft' => $p->openDraft ? ['version' => $p->openDraft->number, 'status' => $p->openDraft->status] : null,
            ]);

        return response()->json(['data' => $programmes]);
    }

    public function show(Programme $programme): JsonResponse
    {
        $versions = $programme->versions()->get(['id', 'programme_id', 'number', 'status', 'change_notes', 'published_at', 'retired_at', 'created_at'])
            ->map(fn (ProgrammeVersion $v) => [
                'version' => $v->number, 'status' => $v->status, 'change_notes' => $v->change_notes,
                'published_at' => $v->published_at?->toIso8601String(), 'created_at' => $v->created_at?->toIso8601String(),
            ]);
        $current = $programme->openDraft ?? $programme->published ?? $programme->versions()->first();

        return response()->json(['data' => [
            'programme' => ['code' => $programme->code, 'prefix' => $programme->prefix, 'title' => $programme->title, 'family' => $programme->family, 'status' => $programme->status],
            'versions' => $versions,
            'current' => $current ? ProgrammePresenter::studio($current) : null,
        ]]);
    }

    public function version(Programme $programme, int $number): JsonResponse
    {
        $version = ProgrammeVersion::query()->where('programme_id', $programme->id)->where('number', $number)->firstOrFail();

        return response()->json(['data' => ProgrammePresenter::studio($version)]);
    }
}
