<?php

declare(strict_types=1);

namespace App\Learning;

use App\Audit\AuditLogger;
use App\Models\ChecklistShare;
use App\Models\Enrolment;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\DomainService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Read-only links to a learner's job-ready checklist, made and revoked by the learner (POPIA: their choice).
 *
 * The link shows only the learner's first name and surname initial, the academy, the programme and the
 * checklist. No ID number, contact details or scores. Links expire after 30 days; the token is stored only as
 * a hash, so a database leak cannot be turned into working links.
 */
final class ChecklistShares
{
    public const DAYS = 30;

    public const MAX_LIVE = 5;

    public function __construct(
        private readonly CohortProgress $progress,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{share: ChecklistShare, url: string} */
    public function create(Tenant $tenant, Enrolment $e, ?string $label, User $actor): array
    {
        $live = ChecklistShare::query()->where('enrolment_id', $e->id)->whereNull('revoked_at')->where('expires_at', '>', now())->count();
        if ($live >= self::MAX_LIVE) {
            throw ValidationException::withMessages(['share' => 'You already have '.self::MAX_LIVE.' links. Stop sharing one first.']);
        }
        $token = Str::random(40);
        $share = ChecklistShare::query()->create(['enrolment_id' => $e->id, 'token_hash' => hash('sha256', $token), 'label' => $label, 'expires_at' => now()->addDays(self::DAYS)]);
        $this->audit->record('checklist.shared', $share, null, ['enrolment' => $e->id, 'expires_at' => $share->expires_at->toIso8601String()], $actor);

        return ['share' => $share, 'url' => self::url($tenant, $token)];
    }

    public function revoke(ChecklistShare $share, User $actor): void
    {
        $share->update(['revoked_at' => now()]);
        $this->audit->record('checklist.share_revoked', $share, null, [], $actor);
    }

    /**
     * What a mentor or employer sees, or null when the link is unknown, expired or revoked.
     *
     * @return array<string, mixed>|null
     */
    public function view(Tenant $tenant, string $token): ?array
    {
        $share = ChecklistShare::query()->with(['enrolment.learner', 'enrolment.programme'])->where('token_hash', hash('sha256', $token))->first();
        if ($share === null || ! $share->isLive()) {
            return null;
        }
        $share->update(['views' => $share->views + 1, 'last_viewed_at' => now()]);
        $learner = $share->enrolment->learner;

        return [
            'learner' => trim((strtok($learner->first_names, ' ') ?: $learner->first_names).' '.mb_substr($learner->surname, 0, 1).'.'),
            'academy' => $tenant->name, 'programme' => $share->enrolment->programme->title,
            'as_of' => now()->toDateString(), 'expires_at' => $share->expires_at->toIso8601String(),
            'areas' => $this->progress->checklist($share->enrolment),
        ];
    }

    /** @return array<string, mixed> */
    public static function present(ChecklistShare $s): array
    {
        return ['id' => $s->id, 'label' => $s->label, 'created_at' => $s->created_at->toIso8601String(), 'expires_at' => $s->expires_at->toIso8601String(), 'views' => $s->views, 'live' => $s->isLive()];
    }

    public static function url(Tenant $tenant, string $token): string
    {
        return 'https://'.DomainService::primaryHost($tenant).'/c/'.$token;
    }
}
