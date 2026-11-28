<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Qcto;

use App\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\Cohort;
use App\Models\Learner;
use App\Models\Tenant;
use App\Models\User;
use App\Qcto\QctoDetails;
use App\Qcto\QctoRegister;
use App\Qcto\XlsxWriter;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Provider: learners' QCTO details, the cohort completeness check, and the QCTO enrolment file. */
final class QctoController extends Controller
{
    public function __construct(
        private readonly QctoDetails $details,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request, Learner $learner): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Equity and disability (special personal information) only for staff who manage learners.
        return response()->json(['data' => QctoDetails::present($learner, $user->hasPermission('learners.manage'))])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, Learner $learner): JsonResponse
    {
        $input = $request->validate(self::rules());
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => QctoDetails::present($this->details->update($learner, $input, $user), true)])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string'], 'middle_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'alt_id_type' => ['sometimes', 'nullable', 'string'], 'equity' => ['sometimes', 'nullable', 'string'],
            'nationality_code' => ['sometimes', 'nullable', 'string'], 'citizen_status' => ['sometimes', 'nullable', 'string'],
            'immigrant_status' => ['sometimes', 'nullable', 'string'], 'socioeconomic_code' => ['sometimes', 'nullable', 'string'],
            'disability_status' => ['sometimes', 'nullable', 'string'], 'disability_rating' => ['sometimes', 'nullable', 'string'],
            'home_address' => ['sometimes', 'nullable', 'array'], 'postal_address' => ['sometimes', 'nullable', 'array'],
            'province_code' => ['sometimes', 'nullable', 'string'], 'stats_area_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'flc_code' => ['sometimes', 'nullable', 'string'], 'flc_sor_number' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    public function check(Cohort $cohort): JsonResponse
    {
        return response()->json(['data' => QctoRegister::check($this->tenant(), $cohort)]);
    }

    /** The QCTO upload file. It contains full ID numbers, so it needs learners.reveal_id and is audited. */
    public function register(Request $request, Cohort $cohort): Response
    {
        $tenant = $this->tenant();
        $rows = QctoRegister::rows($tenant, $cohort);
        $this->audit->record('cohorts.qcto_register_downloaded', $cohort, null, ['learners' => count($rows) - 1], $request->user());
        $name = QctoRegister::fileName($tenant);

        return response(XlsxWriter::write($rows, 'Learner Enrolment'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant */
        return $this->context->tenant();
    }
}
