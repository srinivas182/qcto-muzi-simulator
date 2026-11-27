<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Identity\Authenticator;
use App\Identity\InvitationService;
use App\Identity\PhoneNumber;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Join by invitation link or QR code. */
final class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitations,
        private readonly Authenticator $auth,
    ) {}

    public function show(string $token, TenantContext $context): JsonResponse
    {
        $invitation = $this->invitations->find($token);

        return response()->json(['data' => [
            'name' => $invitation->name,
            'provider' => $context->tenant()?->name,
            'method' => $invitation->phone !== null ? 'phone' : 'email',
            'sent_to' => $invitation->phone !== null ? PhoneNumber::mask($invitation->phone) : $invitation->email,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]]);
    }

    public function sendCode(string $token): JsonResponse
    {
        $channel = $this->invitations->sendCode($this->invitations->find($token));

        return response()->json(['message' => 'Code sent.', 'channel_hint' => $channel === 'sms' ? 'sms' : 'whatsapp'], 202);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:10'],
            'password' => ['nullable', 'string', 'max:200'],
            'consents' => ['required', 'array'],
            'consents.*' => ['boolean'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'client' => ['nullable', 'in:web,mobile'],
        ]);

        $invitation = $this->invitations->find($token);
        $user = $this->invitations->accept($invitation, [
            'code' => $data['code'] ?? null,
            'password' => $data['password'] ?? null,
            'consents' => array_map(fn ($v) => (bool) $v, $data['consents']),
        ], $request);

        $device = (string) ($data['device_name'] ?? Str::limit((string) $request->userAgent(), 90, '') ?: 'Unknown device');

        return response()->json($this->auth->issueToken($user, $device, ($data['client'] ?? 'web')), 201);
    }
}
