<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Centre;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;

/** How a training provider is shown to HQ and to the provider itself. */
final class ProviderPresenter
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return array<string, mixed> */
    public function summary(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'programme_additions' => $tenant->allowsProgrammeAdditions(),
            'accreditation' => $tenant->accreditationState(),
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function details(Tenant $tenant): array
    {
        return $this->summary($tenant) + [
            'legal_name' => $tenant->legal_name,
            'registration_number' => $tenant->registration_number,
            'vat_number' => $tenant->vat_number,
            'accreditation_number' => $tenant->accreditation_number,
            'accreditation_expires_at' => $tenant->accreditation_expires_at?->toDateString(),
            'contact_email' => $tenant->contact_email,
            'contact_phone' => $tenant->contact_phone,
            'billing_email' => $tenant->billing_email,
            'address' => (object) $tenant->address,
            'payment_terms' => $tenant->payment_terms,
            'status_reason' => $tenant->status_reason,
            'status_changed_at' => $tenant->status_changed_at?->toIso8601String(),
        ];
    }

    /**
     * HQ view: details plus addresses, people and centres (read in the provider's context).
     *
     * @return array<string, mixed>
     */
    public function forHq(Tenant $tenant): array
    {
        $extra = $this->context->runAs($tenant, function (): array {
            $people = User::query()->with('roles:id,key,name')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'status' => $u->status,
                'roles' => $u->roles->pluck('name')->unique()->values()->all(),
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ])->all();

            return ['people' => $people, 'centres' => Centre::query()->count()];
        });

        return $this->details($tenant) + $extra + [
            'domains' => $tenant->domains()->orderByDesc('is_primary')->get()
                ->map(fn (TenantDomain $d) => ['host' => $d->host, 'type' => $d->type, 'primary' => $d->is_primary, 'verified' => $d->isVerified()])->all(),
        ];
    }
}
