<?php

declare(strict_types=1);

namespace App\Qcto;

use App\Audit\AuditLogger;
use App\Models\Learner;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * A learner's details for QCTO's enrolment file. Staff (learners.manage) or the learner can fill them in.
 * What the SA ID already tells us (gender, citizenship) is filled in automatically. Equity and disability are
 * encrypted; the audit log records which fields changed, never the values.
 */
final class QctoDetails
{
    /** Required learner-level columns and their QCTO letters, for the completeness check. */
    public const REQUIRED = [
        'title' => 'R', 'equity' => 'F', 'nationality_code' => 'G', 'home_language' => 'H', 'citizen_status' => 'J',
        'socioeconomic_code' => 'K', 'disability_status' => 'L', 'immigrant_status' => 'N', 'date_of_birth' => 'S',
        'home_address' => 'T–U, Z', 'province_code' => 'AF', 'stats_area_code' => 'AG', 'popi' => 'AH–AI', 'flc_code' => 'AO',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $in */
    public function update(Learner $learner, array $in, User $actor): Learner
    {
        $errors = [];
        $set = [];
        $codes = [
            'title' => QctoCodes::TITLES, 'nationality_code' => array_keys(QctoCodes::NATIONALITY), 'citizen_status' => array_keys(QctoCodes::CITIZEN),
            'immigrant_status' => array_map('strval', array_keys(QctoCodes::IMMIGRANT)), 'socioeconomic_code' => array_map('strval', array_keys(QctoCodes::SOCIOECONOMIC)),
            'province_code' => array_map('strval', array_keys(QctoCodes::PROVINCE)), 'flc_code' => array_map('strval', array_keys(QctoCodes::FLC)),
            'alt_id_type' => array_map('strval', array_keys(QctoCodes::ALT_ID_TYPES)),
        ];
        foreach ($codes as $field => $allowed) {
            if (array_key_exists($field, $in)) {
                $v = $in[$field] === null || $in[$field] === '' ? null : (string) $in[$field];
                if ($v !== null && ! in_array($v, $allowed, true)) {
                    $errors[$field] = 'Choose a value from the list.';
                }
                $set[$field] = $v;
            }
        }
        if (array_key_exists('middle_name', $in)) {
            $set['middle_name'] = trim((string) $in['middle_name']) ?: null;
        }
        if (array_key_exists('stats_area_code', $in)) {
            $v = strtoupper(trim((string) $in['stats_area_code']));
            if ($v !== '' && preg_match('/^[A-Z0-9]{1,20}$/', $v) !== 1) {
                $errors['stats_area_code'] = 'Use the Stats SA code: letters and digits only.';
            }
            $set['stats_area_code'] = $v ?: null;
        }
        if (array_key_exists('flc_sor_number', $in)) {
            $set['flc_sor_number'] = trim((string) $in['flc_sor_number']) ?: null;
        }
        foreach (['home_address', 'postal_address'] as $field) {
            if (array_key_exists($field, $in)) {
                if ($in[$field] === null) {
                    $set[$field] = null;

                    continue;
                }
                $a = array_map(fn ($v) => trim((string) $v), (array) $in[$field] + ['line1' => '', 'line2' => '', 'line3' => '', 'postal_code' => '']);
                $bad = '/[^\p{L}0-9 \-#&()\/\\\\:._\',]/u';
                if ($a['line1'] === '' || ctype_digit(str_replace(' ', '', $a['line1'])) || $a['line2'] === '') {
                    $errors[$field] = 'Enter the street (number and name) and the suburb or town.';
                } elseif (preg_match($bad, $a['line1'].$a['line2'].$a['line3']) === 1) {
                    $errors[$field] = 'Use letters, digits and simple punctuation only.';
                } elseif (preg_match('/^\d{4}$/', $a['postal_code']) !== 1) {
                    $errors[$field] = 'The postal code has 4 digits.';
                }
                $set[$field] = ['line1' => $a['line1'], 'line2' => $a['line2'], 'line3' => $a['line3'] ?: null, 'postal_code' => $a['postal_code']];
            }
        }
        if (array_key_exists('equity', $in)) {
            $v = $in['equity'] === null || $in['equity'] === '' ? null : (string) $in['equity'];
            if ($v !== null && ! array_key_exists($v, QctoCodes::EQUITY)) {
                $errors['equity'] = 'Choose a value from the list.';
            }
            $set['equity_encrypted'] = $v;
        }
        if (array_key_exists('disability_status', $in)) {
            $status = $in['disability_status'] === null || $in['disability_status'] === '' ? null : (string) $in['disability_status'];
            $rating = $in['disability_rating'] ?? null;
            $rating = $rating === null || $rating === '' ? null : (string) $rating;
            if ($status !== null && ! array_key_exists($status, QctoCodes::DISABILITY)) {
                $errors['disability_status'] = 'Choose a value from the list.';
            } elseif ($status !== null && $status !== 'N' && ($rating === null || ! array_key_exists($rating, QctoCodes::DISABILITY_RATING))) {
                $errors['disability_rating'] = 'Choose how much difficulty the learner has.';
            }
            $set['disability_encrypted'] = $status === null ? null : ['status' => $status, 'rating' => $status === 'N' ? null : $rating];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $learner->fill($set);
        $changed = array_map(fn (string $f) => str_replace('_encrypted', '', $f), array_keys($learner->getDirty()));
        $learner->save();
        if ($changed !== []) {
            $this->audit->record('learners.qcto_changed', $learner, null, ['fields' => $changed, 'by_learner' => $learner->user_id === $actor->id], $actor);
        }

        return $learner;
    }

    /**
     * Fill what the SA ID tells us, if not set yet. Called when a learner is added and when details are read.
     */
    public static function defaults(Learner $l): void
    {
        if ($l->id_type === 'sa_id') {
            $l->citizen_status ??= $l->citizenship === 'permanent_resident' ? 'PR' : 'SA';
            $l->nationality_code ??= $l->citizenship === 'permanent_resident' ? null : 'SA';
            $l->immigrant_status ??= $l->citizenship === 'permanent_resident' ? null : '03';
        } else {
            $l->alt_id_type ??= '527';
        }
    }

    /** @return array<string, mixed> */
    public static function present(Learner $l, bool $withSensitive): array
    {
        self::defaults($l);

        return [
            'title' => $l->title, 'middle_name' => $l->middle_name, 'alt_id_type' => $l->alt_id_type,
            'nationality_code' => $l->nationality_code, 'citizen_status' => $l->citizen_status, 'immigrant_status' => $l->immigrant_status,
            'socioeconomic_code' => $l->socioeconomic_code, 'home_address' => $l->home_address, 'postal_address' => $l->postal_address,
            'province_code' => $l->province_code, 'stats_area_code' => $l->stats_area_code, 'flc_code' => $l->flc_code, 'flc_sor_number' => $l->flc_sor_number,
            'popi_agreed_at' => $l->popi_agreed_at?->toIso8601String(),
            'equity' => $withSensitive ? $l->equity_encrypted : null,
            'disability_status' => $withSensitive ? ($l->disability_encrypted['status'] ?? null) : null,
            'disability_rating' => $withSensitive ? ($l->disability_encrypted['rating'] ?? null) : null,
            'missing' => self::missing($l),
        ];
    }

    /**
     * Required QCTO details still missing for this learner, as field keys.
     *
     * @return list<string>
     */
    public static function missing(Learner $l): array
    {
        self::defaults($l);
        $have = [
            'title' => $l->title, 'equity' => $l->equity_encrypted, 'nationality_code' => $l->nationality_code, 'home_language' => $l->home_language,
            'citizen_status' => $l->citizen_status, 'socioeconomic_code' => $l->socioeconomic_code, 'disability_status' => $l->disability_encrypted['status'] ?? null,
            'immigrant_status' => $l->immigrant_status, 'date_of_birth' => $l->date_of_birth, 'home_address' => $l->home_address,
            'province_code' => $l->province_code, 'stats_area_code' => $l->stats_area_code, 'popi' => $l->popi_agreed_at, 'flc_code' => $l->flc_code,
        ];

        return array_keys(array_filter($have, fn ($v) => $v === null || $v === '' || $v === []));
    }
}
