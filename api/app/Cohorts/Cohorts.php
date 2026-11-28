<?php

declare(strict_types=1);

namespace App\Cohorts;

use App\Audit\AuditLogger;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\ProgrammeVersion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cohorts: create, change, facilitators, QR join code, archive, and joining by code. */
final class Cohorts
{
    /** Letters and digits that are hard to confuse when read aloud or typed (no 0/O, 1/I/L). */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Enrolments $enrolments,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, User $actor): Cohort
    {
        $version = ProgrammeVersion::query()->where('programme_id', $input['programme_id'])->where('status', 'published')->first();
        if ($version === null) {
            throw ValidationException::withMessages(['programme_id' => 'This programme has no published version yet.']);
        }

        return DB::transaction(function () use ($input, $version, $actor): Cohort {
            $cohort = Cohort::query()->create([
                'name' => $input['name'], 'centre_id' => $input['centre_id'], 'programme_id' => $version->programme_id,
                'programme_version_id' => $version->id, 'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on'] ?? null,
                'qcto_type' => $input['qcto_type'] ?? 'qualification', 'join_code' => self::newCode(), 'created_by' => $actor->id,
            ]);
            $this->setFacilitators($cohort, $input['facilitator_ids'] ?? []);
            $this->audit->record('cohorts.created', $cohort, null, ['name' => $cohort->name, 'version' => $version->number], $actor);

            return $cohort->refresh(); // database defaults (join_open)
        });
    }

    /**
     * The programme and its pinned version never change; everything else can.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(Cohort $cohort, array $input, User $actor): Cohort
    {
        DB::transaction(function () use ($cohort, $input, $actor): void {
            $cohort->fill(array_intersect_key($input, array_flip(['name', 'centre_id', 'starts_on', 'ends_on', 'qcto_type', 'join_open'])));
            if (array_key_exists('qcto_submitted', $input)) {
                $cohort->qcto_submitted_at = $input['qcto_submitted'] ? ($cohort->qcto_submitted_at ?? now()) : null;
            }
            if ($cohort->ends_on !== null && $cohort->ends_on->lt($cohort->starts_on)) {
                throw ValidationException::withMessages(['ends_on' => 'The end date must be on or after the start date.']);
            }
            $changed = array_keys($cohort->getDirty());
            $cohort->save();
            if (array_key_exists('facilitator_ids', $input)) {
                $this->setFacilitators($cohort, $input['facilitator_ids']);
                $changed[] = 'facilitators';
            }
            if ($changed !== []) {
                $this->audit->record('cohorts.changed', $cohort, null, ['fields' => $changed], $actor);
            }
        });

        return $cohort;
    }

    public function newJoinCode(Cohort $cohort, User $actor): Cohort
    {
        $cohort->update(['join_code' => self::newCode()]);
        $this->audit->record('cohorts.join_code_changed', $cohort, null, [], $actor);

        return $cohort;
    }

    public function archive(Cohort $cohort, User $actor): Cohort
    {
        if (Enrolment::query()->where('cohort_id', $cohort->id)->whereIn('status', Enrolment::CURRENT)->exists()) {
            throw ValidationException::withMessages(['cohort' => 'Withdraw, complete or transfer the learners in this cohort first.']);
        }
        $cohort->update(['archived_at' => now(), 'join_open' => false]);
        $this->audit->record('cohorts.archived', $cohort, null, [], $actor);

        return $cohort;
    }

    /**
     * A learner scans the cohort QR code in class. Only learners already on the provider's register can join
     * (their ID number is checked when they are added), and joining takes a seat like any enrolment.
     */
    public function join(Tenant $tenant, string $code, User $user): Enrolment
    {
        $cohort = Cohort::query()->where('join_code', strtoupper(trim($code)))->whereNull('archived_at')->first();
        if ($cohort === null || ! $cohort->join_open) {
            throw ValidationException::withMessages(['code' => 'This join code is not valid or the cohort is closed. Ask your facilitator.']);
        }
        $learner = Learner::query()->where('user_id', $user->id)->first();
        if ($learner === null) {
            throw ValidationException::withMessages(['code' => 'Your provider has not added you as a learner yet. Ask your facilitator.']);
        }
        $existing = Enrolment::query()->where('learner_id', $learner->id)->where('cohort_id', $cohort->id)->whereIn('status', Enrolment::CURRENT)->first();
        if ($existing !== null) {
            return $existing; // scanning twice is fine
        }
        $result = $this->enrolments->enrol($tenant, $cohort, [$learner->id], $user);
        if ($result['enrolled'] === []) {
            throw ValidationException::withMessages(['code' => $result['skipped'][0]['reason'] ?? 'You could not be added to this cohort.']);
        }

        return $result['enrolled'][0];
    }

    /** @param list<string>|mixed $ids */
    private function setFacilitators(Cohort $cohort, mixed $ids): void
    {
        $ids = array_values(array_unique(array_map('strval', (array) $ids)));
        $staff = User::query()->whereIn('id', $ids)->get()->filter(fn (User $u) => $u->hasPermission('classes.view'));
        if ($staff->count() !== count($ids)) {
            throw ValidationException::withMessages(['facilitator_ids' => 'Facilitators must be staff of this provider who can see classes.']);
        }
        $cohort->facilitators()->syncWithPivotValues($ids, ['tenant_id' => $cohort->tenant_id]);
    }

    private static function newCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (Cohort::query()->where('join_code', $code)->exists()); // 31^8 codes: a clash elsewhere is negligible

        return $code;
    }
}
