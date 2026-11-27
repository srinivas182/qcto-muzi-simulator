<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Identity\Authenticator;
use App\Identity\ConsentService;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Sanctum\PersonalAccessToken;

/** The signed-in person's account: profile, devices, consent and sign-out. */
final class AccountController extends Controller
{
    public function __construct(
        private readonly Authenticator $auth,
        private readonly ConsentService $consents,
        private readonly AuditLogger $audit,
    ) {}

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->auth->present($this->user($request))]);
    }

    /** Personal preferences, e.g. the app language. */
    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys((array) config('app.locales')))]]);
        $user = $this->user($request);
        $user->forceFill(['locale' => $data['locale']])->save();

        return response()->json(['data' => $this->auth->present($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record('auth.logout', $this->user($request));
        $this->user($request)->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** Devices where this account is signed in. */
    public function sessions(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $currentId = $user->currentAccessToken()->id;

        $sessions = $user->tokens()->latest('last_used_at')->get()->map(fn (PersonalAccessToken $t) => [
            'id' => $t->id,
            'device' => $t->name,
            'last_used_at' => $t->last_used_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'expires_at' => $t->expires_at?->toIso8601String(),
            'current' => $t->id === $currentId,
        ]);

        return response()->json(['data' => $sessions]);
    }

    public function revokeSession(Request $request, string $id): JsonResponse
    {
        $deleted = $this->user($request)->tokens()->whereKey($id)->delete();
        abort_if($deleted === 0, 404);
        $this->audit->record('auth.session_revoked', $this->user($request), null, ['token_id' => $id]);

        return response()->json(['message' => 'Device signed out.']);
    }

    public function consent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', Rule::in(array_keys($this->consents->current()))],
            'granted' => ['required', 'boolean'],
        ]);

        $user = $this->user($request);
        $this->consents->record($user, $data['purpose'], (bool) $data['granted'], $request);

        return response()->json(['data' => $this->auth->present($user)]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
