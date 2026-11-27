<?php

declare(strict_types=1);

namespace App\Support;

use App\Audit\AuditLogger;
use App\Models\SupportSession;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * HQ read-only support views of a training provider.
 * Needs the support.login permission and two-factor authentication, a written reason and a time
 * limit (max 60 minutes). The provider can see every session and end it at any time.
 */
final class SupportSessionService
{
    public const MAX_MINUTES = 60;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{session: SupportSession, token: string} */
    public function start(User $staff, Tenant $tenant, string $reason, int $minutes): array
    {
        if (! $staff->isPlatformStaff() || ! $staff->hasPermission('support.login')) {
            throw ValidationException::withMessages(['tenant_id' => 'You are not allowed to open support views.'])->status(403);
        }
        if (! $staff->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['two_factor' => 'Turn on two-factor authentication before opening a support view.']);
        }
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'Describe why you need access (at least 10 characters).']);
        }
        $minutes = max(5, min(self::MAX_MINUTES, $minutes));

        $session = new SupportSession([
            'staff_user_id' => $staff->id,
            'staff_name' => $staff->name,
            'reason' => trim($reason),
            'expires_at' => now()->addMinutes($minutes),
        ]);
        $session->tenant_id = $tenant->id;
        $session->save();

        $token = $staff->createToken('support:'.$session->id, ['support'], $session->expires_at);
        $session->forceFill(['token_id' => $token->accessToken->getKey()])->save();

        $this->audit->record('support.started', $session, null, ['reason' => $session->reason, 'minutes' => $minutes], $staff);

        return ['session' => $session, 'token' => $token->plainTextToken];
    }

    public function end(SupportSession $session, string $by, ?User $actor = null): void
    {
        if ($session->ended_at !== null) {
            return;
        }

        $session->forceFill(['ended_at' => now(), 'ended_by' => $by])->save();

        if ($session->token_id !== null) {
            $this->context->runAs(null, fn () => PersonalAccessToken::query()->whereKey($session->token_id)->delete(), platform: true);
        }

        $this->audit->record('support.ended', $session, null, ['ended_by' => $by], $actor);
    }
}
