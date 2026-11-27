<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Programmes;

use App\Http\Controllers\Controller;
use App\Models\Programme;
use App\Programmes\ProgrammeCatalogue;
use Illuminate\Http\JsonResponse;

/** Provider view of the catalogue: published versions only. */
final class CatalogueController extends Controller
{
    public function __construct(private readonly ProgrammeCatalogue $catalogue) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalogue->published()]);
    }

    public function show(Programme $programme): JsonResponse
    {
        $content = $programme->status === 'active' ? $this->catalogue->publishedVersion($programme) : null;
        abort_if($content === null, 404);

        return response()->json(['data' => $content]);
    }
}
