<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Audit\AuditLogger;
use App\Identity\InvitationService;
use App\Models\Centre;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvitationLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * HQ's management of training providers: onboarding, company and accreditation details, and status.
 *
 * Status effects:
 *  - trial / active: normal use;
 *  - suspended: provider staff see "account on hold" (learners keep learning; HQ support sessions still work);
 *  - archived: the provider's addresses stop resolving, so nobody can sign in. Archiving is final.
 * Every change is audited under the provider, so it also appears in the provider's own audit log.
 */
final class ProviderAdmin
{
    /** Fields only HQ may change (legal identity and accreditation). */
    public const HQ_FIELDS = ['name', 'legal_name', 'registration_number', 'vat_number', 'accreditation_number', 'accreditation_expires_at', 'payment_terms'];

    /** Fields the provider may change itself. */
    public const PROVIDER_FIELDS = ['contact_email', 'contact_phone', 'billing_email', 'address'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
        private readonly DomainService $domains,
        private readonly InvitationService $invitations,
    ) {}

    /**
     * Create a provider with its sub-domain and a first centre, and invite its first TP Admin by email.
     *
     * @param  array<string, mixed>  $details
     * @return array{tenant: Tenant, invite_url: string}
     */
    public function create(array $details, string $adminName, string $adminEmail, User $actor): array
    {
        $slug = Str::slug((string) ($details['slug'] ?? $details['name']));
        if ($slug === '' || in_array($slug, TenantResolver::reservedSubdomains(), true) || Tenant::withTrashed()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'This web address is not available. Choose another.']);
        }

        return DB::transaction(function () use ($details, $slug, $adminName, $adminEmail, $actor): array {
            $tenant = Tenant::query()->create(['slug' => $slug, 'status' => Tenant::STATUS_TRIAL, 'status_changed_at' => now()]
                + array_intersect_key($details, array_flip([...self::HQ_FIELDS, ...self::PROVIDER_FIELDS])));
            $this->domains->createSubdomain($tenant);

            $invite = $this->context->runAs($tenant, function () use ($tenant, $adminName, $adminEmail, $actor): array {
                Centre::query()->create(['name' => 'Main campus']);
                $invite = $this->invitations->create($tenant, $adminName, 'tp_admin', null, $adminEmail, $actor);
                $this->audit->record('providers.created', $tenant, null, ['slug' => $tenant->slug, 'admin_email' => $adminEmail], $actor);

                return $invite;
            });

            Notification::route('mail', $adminEmail)->notify(new InvitationLink($tenant->name, $invite['url']));

            return ['tenant' => $tenant, 'invite_url' => $invite['url']];
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  list<string>  $allowed
     */
    public function update(Tenant $tenant, array $changes, array $allowed, User $actor): Tenant
    {
        $changes = array_intersect_key($changes, array_flip($allowed));
        $before = [];
        foreach (array_keys($changes) as $key) {
            $before[$key] = $tenant->getAttribute($key) instanceof \DateTimeInterface ? $tenant->getAttribute($key)->format('Y-m-d') : $tenant->getAttribute($key);
        }
        $tenant->fill($changes);
        if (! $tenant->isDirty()) {
            return $tenant;
        }
        $tenant->save();
        $this->context->runAs($tenant, fn () => $this->audit->record('providers.details_changed', $tenant, ['before' => $before, 'after' => $changes], [], $actor));

        return $tenant;
    }

    public function changeStatus(Tenant $tenant, string $status, string $reason, User $actor): Tenant
    {
        if (! in_array($status, Tenant::TRANSITIONS[$tenant->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$tenant->status} provider cannot be moved to {$status}."]);
        }

        $before = $tenant->status;
        $tenant->forceFill(['status' => $status, 'status_reason' => $reason, 'status_changed_at' => now()])->save();
        $this->context->runAs($tenant, fn () => $this->audit->record('providers.status_changed', $tenant, ['before' => $before, 'after' => $status], ['reason' => $reason], $actor));

        return $tenant;
    }

    /** Invite another TP Admin (for example when the first invitation expired). */
    public function inviteAdmin(Tenant $tenant, string $name, string $email, User $actor): string
    {
        $invite = $this->context->runAs($tenant, fn () => $this->invitations->create($tenant, $name, 'tp_admin', null, $email, $actor));
        Notification::route('mail', $email)->notify(new InvitationLink($tenant->name, $invite['url']));

        return $invite['url'];
    }
}
