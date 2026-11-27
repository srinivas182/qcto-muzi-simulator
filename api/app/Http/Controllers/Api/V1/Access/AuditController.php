<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Access;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Audit log. Row-level security decides what is visible: a provider's own entries, or all at HQ. */
final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:80'],
            'actor_id' => ['nullable', 'uuid'],
            'tenant_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $page = AuditLog::query()
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', 'like', $v.'%'))
            ->when($filters['actor_id'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($filters['tenant_id'] ?? null, fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->latest('created_at')
            ->cursorPaginate(50);

        return response()->json($page);
    }
}
