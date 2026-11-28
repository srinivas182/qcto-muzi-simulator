<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Assessment;

use App\Assessment\CameraChecks;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The provider's assessment settings (Sprint 5.1c): whether tests may use the camera check. */
final class AssessmentSettingsController extends Controller
{
    public function show(TenantContext $context): JsonResponse
    {
        $tenant = $context->tenant() ?? throw new NotFoundHttpException;

        return response()->json(['data' => ['camera_checks' => $tenant->settings['camera_checks'] ?? ['on' => false]]]);
    }

    public function update(Request $request, TenantContext $context, CameraChecks $camera): JsonResponse
    {
        $tenant = $context->tenant() ?? throw new NotFoundHttpException;
        $data = $request->validate(['camera_checks' => ['required', 'boolean'], 'accept_dpia' => ['required_if:camera_checks,true', 'accepted_if:camera_checks,true']]);
        /** @var User $actor */
        $actor = $request->user();
        $camera->configure($tenant, (bool) $data['camera_checks'], $actor);

        return $this->show($context);
    }
}
