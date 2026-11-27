<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Identity\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetService $resets) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $this->resets->request($data['email'], $request->getSchemeAndHttpHost());

        return response()->json(['message' => 'If this email has an account, a reset link is on its way.'], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'min:10', 'max:200', 'confirmed'],
        ]);
        $this->resets->reset($data['token'], $data['password']);

        return response()->json(['message' => 'Password changed. Please sign in again.']);
    }
}
