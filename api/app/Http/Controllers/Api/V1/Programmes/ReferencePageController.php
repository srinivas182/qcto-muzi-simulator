<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Programmes;

use App\Http\Controllers\Controller;
use App\Models\ReferencePage;
use Illuminate\Http\JsonResponse;

/** The guide's general chapters (framework, mentor's guide, programme index). */
final class ReferencePageController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => ReferencePage::query()->orderBy('position')->get(['slug', 'title', 'position'])]);
    }

    public function show(ReferencePage $page): JsonResponse
    {
        return response()->json(['data' => $page->only(['slug', 'title', 'markdown', 'source'])]);
    }
}
