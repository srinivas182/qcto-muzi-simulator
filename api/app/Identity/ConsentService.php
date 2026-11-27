<?php

declare(strict_types=1);

namespace App\Identity;

use App\Audit\AuditLogger;
use App\Models\User;
use App\Models\UserConsent;
use Illuminate\Http\Request;

/**
 * POPIA consent records. Each purpose has a version; when the version changes, people are asked
 * again at their next sign-in. Every decision is stored with time, IP address and device.
 */
final class ConsentService
{
    /** @return array<string, string> purpose => current version */
    public function current(): array
    {
        /** @var array<string, string> $versions */
        $versions = config('identity.consents', []);

        return $versions;
    }

    /** @return list<string> purposes the user still has to agree to */
    public function outstanding(User $user): array
    {
        $outstanding = [];

        foreach ($this->current() as $purpose => $version) {
            $latest = UserConsent::query()
                ->where('user_id', $user->id)
                ->where('purpose', $purpose)
                ->latest()
                ->first();

            if ($latest === null || ! $latest->granted || $latest->version !== $version) {
                $outstanding[] = $purpose;
            }
        }

        return $outstanding;
    }

    public function record(User $user, string $purpose, bool $granted, ?Request $request = null): UserConsent
    {
        $consent = new UserConsent([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'version' => $this->current()[$purpose] ?? 'unversioned',
            'granted' => $granted,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
        ]);
        $consent->tenant_id = $user->tenant_id;
        $consent->save();
        app(AuditLogger::class)->record($granted ? 'consent.granted' : 'consent.withdrawn', $user, null, ['purpose' => $purpose, 'version' => $consent->version], $user);

        return $consent;
    }
}
