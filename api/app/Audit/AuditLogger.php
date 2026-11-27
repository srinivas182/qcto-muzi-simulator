<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Records sensitive actions: who, what, when, where from, and what changed.
 * Entries are append-only (the database refuses updates and deletes).
 */
final class AuditLogger
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>|null  $changes  e.g. ['before' => [...], 'after' => [...]]
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, ?Model $subject = null, ?array $changes = null, array $meta = [], ?User $actor = null): AuditLog
    {
        $request = app()->bound('request') ? app(Request::class) : null;
        $actor ??= $request?->user() instanceof User ? $request->user() : null;

        $tenantId = $this->context->tenantId();
        if ($tenantId === null && $subject !== null && is_string($subject->getAttribute('tenant_id'))) {
            $tenantId = $subject->getAttribute('tenant_id');
        }

        $entry = new AuditLog([
            'tenant_id' => $tenantId,
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'support_session_id' => $actor !== null && $actor->isSupportViewer() ? $meta['support_session_id'] ?? null : null,
            'action' => $action,
            'subject_type' => $subject !== null ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey() !== null ? (string) $subject->getKey() : null,
            'changes' => $changes,
            'meta' => $meta === [] ? null : $meta,
            'ip_address' => $request?->ip(),
            'user_agent' => $request !== null ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
        $entry->save();

        return $entry;
    }
}
