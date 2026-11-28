<?php

declare(strict_types=1);

namespace App\Qcto;

use App\Learners\IdNumbers;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * QCTO's "Learner Enrolment and Readiness for EISA" file for one cohort: 43 fixed columns (A–AQ), all text,
 * file name LEISA{yyyymmdd}-{provider name}. Also the deadline (21 working days after training starts) and a
 * completeness check, so providers fix gaps before they upload.
 */
final class QctoRegister
{
    public const DUE_WORKING_DAYS = 21;

    public const HEADERS = [
        'SDP Code', 'Qualification Id', 'National Id', 'Learner Alternate ID', 'Alternative Id Type', 'Equity Code', 'Nationality Code',
        'Home Language Code', 'Gender Code', 'Citizen Resident Status Code', 'Socioeconomic Status Code', 'Disability Status Code',
        'Disability Rating', 'Immigrant Status', 'Learner Last Name', 'Learner First Name', 'Learner Middle Name', 'Learner Title',
        'Learner Birth Date', 'Learner Home Address 1', 'Learner Home Address 2', 'Learner Home Address 3', 'Learner Postal Address 1',
        'Learner Postal Address 2', 'Learner Postal Address 3', 'Learner Home Address Postal Code', 'Learner Postal Address Post Code',
        'Learner Phone Number', 'Learner Cell Phone Number', 'Learner Fax Number', 'Learner Email Address', 'Province Code',
        'STATSSA Area Code', 'POPI Act Agree', 'POPI Act Date', 'Expected Training Completion Date', 'Statement of Results Status',
        'Statement of Results Issue Date', 'Assessment Centre Code', 'Learner Readiness for EISA Type Id', 'FLC',
        'FLC Statement of Result Number', 'Date Stamp',
    ];

    /** Due date, or null for cohorts not reported to QCTO. */
    public static function dueOn(Cohort $c): ?Carbon
    {
        return $c->qcto_type === 'none' ? null : WorkingDays::add($c->starts_on, self::DUE_WORKING_DAYS);
    }

    /** @return array{cohort: list<string>, learners: list<array{learner_id: string, name: string, missing: list<string>}>, complete: int, total: int} */
    public static function check(Tenant $tenant, Cohort $c): array
    {
        $cohort = array_keys(array_filter([
            'sdp_code' => ($tenant->qcto_sdp_code ?? $tenant->accreditation_number) === null,
            'qualification_id' => $c->qcto_qualification_id === null,
            'ends_on' => $c->ends_on === null,
        ]));
        $learners = [];
        $complete = 0;
        foreach (self::enrolments($c) as $e) {
            $missing = QctoDetails::missing($e->learner);
            $complete += $missing === [] ? 1 : 0;
            $learners[] = ['learner_id' => $e->learner_id, 'name' => $e->learner->fullName(), 'missing' => $missing];
        }

        return ['cohort' => $cohort, 'learners' => $learners, 'complete' => $complete, 'total' => count($learners)];
    }

    /** @return list<list<string>> header row first */
    public static function rows(Tenant $tenant, Cohort $c): array
    {
        $rows = [self::HEADERS];
        $ymd = fn (?Carbon $d) => $d?->format('Ymd') ?? '';
        foreach (self::enrolments($c) as $e) {
            $l = $e->learner;
            QctoDetails::defaults($l);
            $number = IdNumbers::reveal($l->id_number_encrypted);
            $sa = $l->id_type === 'sa_id';
            $home = $l->home_address ?? [];
            $postal = $l->postal_address ?? $home;
            $status = $l->disability_encrypted['status'] ?? '';
            $rows[] = [
                (string) ($tenant->qcto_sdp_code ?? $tenant->accreditation_number ?? ''), (string) $c->qcto_qualification_id,
                $sa ? $number : '', $sa ? '' : $number, $sa ? '533' : (string) ($l->alt_id_type ?? '527'),
                (string) $l->equity_encrypted, (string) $l->nationality_code, QctoCodes::LANGUAGE[$l->home_language ?? ''] ?? '',
                match ($l->gender) {
                    'male' => 'M', 'female' => 'F', default => ''
                }, (string) $l->citizen_status, (string) $l->socioeconomic_code,
                (string) $status, $status !== '' && $status !== 'N' ? (string) ($l->disability_encrypted['rating'] ?? '') : '', (string) $l->immigrant_status,
                $l->surname, $l->first_names, (string) $l->middle_name, (string) $l->title, $ymd($l->date_of_birth),
                (string) ($home['line1'] ?? ''), (string) ($home['line2'] ?? ''), (string) ($home['line3'] ?? ''),
                (string) ($postal['line1'] ?? ''), (string) ($postal['line2'] ?? ''), (string) ($postal['line3'] ?? ''),
                (string) ($home['postal_code'] ?? ''), (string) ($postal['postal_code'] ?? ''),
                '', self::localPhone($l->phone), '', (string) $l->email,
                (string) $l->province_code, (string) $l->stats_area_code, $l->popi_agreed_at !== null ? 'Yes' : 'No', $ymd($l->popi_agreed_at),
                $ymd($c->ends_on), $e->sor_issued_on !== null ? '01' : '02', $e->sor_issued_on !== null ? Carbon::parse($e->sor_issued_on)->format('Ymd') : '',
                '', $c->readiness_type, (string) $l->flc_code, (string) $l->flc_sor_number, $ymd($l->updated_at),
            ];
        }

        return $rows;
    }

    public static function fileName(Tenant $tenant): string
    {
        $name = trim((string) preg_replace('/[^A-Za-z0-9 &()-]+/', '', $tenant->legal_name ?? $tenant->name));

        return 'LEISA'.now()->format('Ymd').'-'.$name.'.xlsx';
    }

    /** @return iterable<Enrolment> */
    private static function enrolments(Cohort $c): iterable
    {
        return Enrolment::query()->with('learner')->where('cohort_id', $c->id)
            ->whereIn('status', [Enrolment::ENROLLED, Enrolment::ACTIVE, Enrolment::COMPLETED])->get()
            ->sortBy(fn (Enrolment $e) => [$e->learner->surname, $e->learner->first_names]);
    }

    /** +27825550101 → 0825550101 (QCTO: digits only). */
    private static function localPhone(?string $p): string
    {
        return $p === null ? '' : (str_starts_with($p, '+27') ? '0'.substr($p, 3) : preg_replace('/\D/', '', $p) ?? '');
    }
}
