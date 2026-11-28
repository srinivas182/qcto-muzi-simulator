<?php

declare(strict_types=1);

namespace App\Identity;

use App\Audit\AuditLogger;
use App\Authorization\RoleService;
use App\Learners\LearnerDirectory;
use App\Models\Invitation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\DomainService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitations: the provider invites a person by mobile number or email; they open the link
 * (or scan the QR code), prove the number with a one-time code (or set a password for email
 * invites), agree to the POPIA notice, and their account is ready.
 */
final class InvitationService
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly ConsentService $consents,
        private readonly RoleService $roles,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{invitation: Invitation, token: string, url: string} */
    public function create(Tenant $tenant, string $name, string $role, ?string $phone, ?string $email, ?User $invitedBy = null): array
    {
        $phone = $phone !== null ? PhoneNumber::normalise($phone) : null;
        $email = $email !== null ? Str::lower(trim($email)) : null;

        if ($phone === null && ($email === null || $email === '')) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid mobile number or an email address.']);
        }

        $assignable = Role::query()->where('key', $role)
            ->whereIn('scope', [Role::SCOPE_TENANT, Role::SCOPE_EXTERNAL])->exists();
        if (! $assignable) {
            throw ValidationException::withMessages(['role' => 'Choose a valid role for this person.']);
        }

        $token = Str::random(40);
        $invitation = Invitation::query()->create([
            'name' => $name,
            'phone' => $phone,
            'email' => $email ?: null,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'invited_by' => $invitedBy?->id,
            'expires_at' => now()->addDays((int) config('identity.invitations.ttl_days')),
        ]);

        return ['invitation' => $invitation, 'token' => $token, 'url' => $this->url($tenant, $token)];
    }

    public function find(string $token): Invitation
    {
        $invitation = Invitation::query()->where('token_hash', hash('sha256', $token))->first();

        if ($invitation === null || ! $invitation->isUsable()) {
            throw ValidationException::withMessages(['token' => 'This invitation has expired or was already used. Ask your training provider for a new one.']);
        }

        return $invitation;
    }

    public function sendCode(Invitation $invitation): string
    {
        if ($invitation->phone === null) {
            throw ValidationException::withMessages(['phone' => 'This invitation was sent by email; set a password instead.']);
        }

        return $this->otp->send($invitation->phone, 'invite:'.$invitation->id);
    }

    /**
     * @param  array{code?: string|null, password?: string|null, consents: array<string, bool>}  $input
     */
    public function accept(Invitation $invitation, array $input, Request $request): User
    {
        foreach (array_keys($this->consents->current()) as $purpose) {
            if (($input['consents'][$purpose] ?? false) !== true) {
                throw ValidationException::withMessages(["consents.{$purpose}" => 'Please read and accept to continue.']);
            }
        }

        if ($invitation->phone !== null) {
            if (! $this->otp->verify($invitation->phone, 'invite:'.$invitation->id, (string) ($input['code'] ?? ''))) {
                throw ValidationException::withMessages(['code' => 'That code is not valid or has expired.']);
            }
        } elseif (strlen((string) ($input['password'] ?? '')) < 10) {
            throw ValidationException::withMessages(['password' => 'Choose a password of at least 10 characters.']);
        }

        return DB::transaction(function () use ($invitation, $input, $request): User {
            $user = User::query()->create([
                'name' => $invitation->name,
                'phone' => $invitation->phone,
                'email' => $invitation->email,
                'password' => $invitation->phone === null ? $input['password'] : null,
                'phone_verified_at' => $invitation->phone !== null ? now() : null,
                'email_verified_at' => $invitation->phone === null ? now() : null,
            ]);

            foreach (array_keys($this->consents->current()) as $purpose) {
                $this->consents->record($user, $purpose, true, $request);
            }

            $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            $this->roles->assignKey($user, $invitation->role);
            LearnerDirectory::linkAccount($invitation, $user);
            $this->audit->record('invitations.accepted', $user, null, ['role' => $invitation->role], $user);

            return $user;
        });
    }

    private function url(Tenant $tenant, string $token): string
    {
        return 'https://'.DomainService::primaryHost($tenant).'/join/'.$token;
    }
}
