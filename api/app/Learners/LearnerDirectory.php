<?php

declare(strict_types=1);

namespace App\Learners;

use App\Audit\AuditLogger;
use App\Identity\InvitationService;
use App\Identity\Messaging\SmsChannel;
use App\Identity\PhoneNumber;
use App\Models\Invitation;
use App\Models\Learner;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\InvitationLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A provider's learners: add, import, change, invite, deactivate, and (with its own permission) reveal an ID
 * number. All checks for one learner live in `check()`, so a single add and a 500-row import behave the same.
 */
final class LearnerDirectory
{
    public const MAX_IMPORT_ROWS = 1000;

    public function __construct(
        private readonly InvitationService $invitations,
        private readonly AuditLogger $audit,
        private readonly SmsChannel $sms,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(Tenant $tenant, array $input, User $actor, bool $invite): Learner
    {
        [$clean, $errors] = $this->check($input, null);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $learner = DB::transaction(function () use ($clean, $actor): Learner {
            $learner = Learner::query()->create($clean + ['created_by' => $actor->id, 'status' => Learner::INVITED]);
            $this->audit->record('learners.added', $learner, null, ['name' => $learner->fullName()], $actor);

            return $learner;
        });
        if ($invite) {
            $this->invite($tenant, $learner, $actor);
        }

        return $learner;
    }

    /** @param array<string, mixed> $input */
    public function update(Learner $learner, array $input, User $actor): Learner
    {
        [$clean, $errors] = $this->check($input + $this->current($learner), $learner);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $before = $learner->only(['first_names', 'surname', 'phone', 'email', 'home_language', 'id_type', 'id_last4', 'qcto_learner_number']);
        $learner->fill($clean)->save();
        $after = $learner->only(array_keys($before));
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
        if ($changed !== []) {
            // Field names only: ID numbers and contact details never go into the audit log.
            $this->audit->record('learners.changed', $learner, null, ['fields' => $changed], $actor);
        }

        return $learner;
    }

    /**
     * Check every row; with $dryRun nothing is saved. Otherwise the valid rows are added (and optionally invited)
     * and the others are reported back with their errors.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array{row: int, name: string, errors: array<string, string>, learner_id: string|null}>, ready: int, failed: int, created: int}
     */
    public function import(Tenant $tenant, array $rows, bool $dryRun, bool $invite, User $actor): array
    {
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            throw ValidationException::withMessages(['rows' => 'Import at most '.self::MAX_IMPORT_ROWS.' learners at a time.']);
        }
        $seenIds = [];
        $seenPhones = [];
        $report = [];
        $valid = [];
        foreach ($rows as $i => $row) {
            [$clean, $errors] = $this->check($row, null);
            if (isset($clean['id_number_hash'])) {
                if (isset($seenIds[$clean['id_number_hash']])) {
                    $errors['id_number'] = 'The same ID number is on row '.$seenIds[$clean['id_number_hash']].'.';
                }
                $seenIds[$clean['id_number_hash']] ??= $i + 1;
            }
            if (! empty($clean['phone'])) {
                if (isset($seenPhones[$clean['phone']])) {
                    $errors['phone'] = 'The same mobile number is on row '.$seenPhones[$clean['phone']].'.';
                }
                $seenPhones[$clean['phone']] ??= $i + 1;
            }
            $name = trim(($row['first_names'] ?? '').' '.($row['surname'] ?? ''));
            $report[$i] = ['row' => $i + 1, 'name' => $name, 'errors' => $errors, 'learner_id' => null];
            if ($errors === []) {
                $valid[$i] = $clean;
            }
        }

        $created = 0;
        if (! $dryRun && $valid !== []) {
            DB::transaction(function () use ($valid, $actor, &$report, &$created): void {
                foreach ($valid as $i => $clean) {
                    $learner = Learner::query()->create($clean + ['created_by' => $actor->id, 'status' => Learner::INVITED]);
                    $report[$i]['learner_id'] = $learner->id;
                    $created++;
                }
                $this->audit->record('learners.imported', null, null, ['count' => $created], $actor);
            });
            if ($invite) {
                foreach ($report as $r) {
                    if ($r['learner_id'] !== null) {
                        $this->invite($tenant, Learner::query()->findOrFail($r['learner_id']), $actor);
                    }
                }
            }
        }

        return ['rows' => array_values($report), 'ready' => count($valid), 'failed' => count($rows) - count($valid), 'created' => $created];
    }

    /**
     * (Re)send an invitation by SMS or email, and return the link so staff can also share it by WhatsApp.
     *
     * @return array{url: string, channel: string}
     */
    public function invite(Tenant $tenant, Learner $learner, User $actor): array
    {
        if ($learner->user_id !== null) {
            throw ValidationException::withMessages(['learner' => 'This learner has already joined.']);
        }
        Invitation::query()->where('learner_id', $learner->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $created = $this->invitations->create($tenant, $learner->fullName(), 'learner', $learner->phone, $learner->email, $actor);
        $created['invitation']->forceFill(['learner_id' => $learner->id])->save();

        $channel = 'link';
        try {
            if ($learner->phone !== null && $this->sms->isConfigured()) {
                $this->sms->sendText($learner->phone, "{$tenant->name}: you are invited to SimuWork. Join here: {$created['url']}");
                $channel = 'sms';
            } elseif ($learner->email !== null) {
                Notification::route('mail', $learner->email)->notify(new InvitationLink($tenant->name, $created['url']));
                $channel = 'email';
            } else {
                Log::info('Learner invitation created; no SMS provider configured, share the link.', ['learner' => $learner->id]);
            }
        } catch (Throwable $e) {
            report($e);
        }
        $this->audit->record('learners.invited', $learner, null, ['channel' => $channel], $actor);

        return ['url' => $created['url'], 'channel' => $channel];
    }

    public function setActive(Learner $learner, bool $active, User $actor): Learner
    {
        $status = $active ? ($learner->user_id !== null ? Learner::ACTIVE : Learner::INVITED) : Learner::INACTIVE;
        $learner->update(['status' => $status]);
        $learner->user?->forceFill(['status' => $active ? User::STATUS_ACTIVE : User::STATUS_DISABLED])->save();
        if (! $active) {
            $learner->user?->tokens()->delete();
            Invitation::query()->where('learner_id', $learner->id)->whereNull('accepted_at')->update(['revoked_at' => now()]);
        }
        $this->audit->record($active ? 'learners.activated' : 'learners.deactivated', $learner, null, [], $actor);

        return $learner;
    }

    public function reveal(Learner $learner, User $actor): string
    {
        $this->audit->record('learners.id_revealed', $learner, null, ['name' => $learner->fullName()], $actor);

        return IdNumbers::reveal($learner->id_number_encrypted);
    }

    /** Called when a learner accepts their invitation. */
    public static function linkAccount(Invitation $invitation, User $user): void
    {
        if ($invitation->learner_id === null) {
            return;
        }
        Learner::query()->whereKey($invitation->learner_id)->update(['user_id' => $user->id, 'status' => Learner::ACTIVE, 'joined_at' => now()]);
    }

    /** @return array<string, mixed> */
    public static function present(Learner $l): array
    {
        return [
            'id' => $l->id,
            'first_names' => $l->first_names,
            'surname' => $l->surname,
            'name' => $l->fullName(),
            'phone' => $l->phone,
            'email' => $l->email,
            'id_type' => $l->id_type,
            'id_masked' => str_repeat('•', $l->id_type === 'sa_id' ? 9 : 4).$l->id_last4,
            'date_of_birth' => $l->date_of_birth?->toDateString(),
            'gender' => $l->gender,
            'citizenship' => $l->citizenship,
            'home_language' => $l->home_language,
            'qcto_learner_number' => $l->qcto_learner_number,
            'status' => $l->status,
            'joined_at' => $l->joined_at?->toIso8601String(),
            'created_at' => $l->created_at->toIso8601String(),
        ];
    }

    /**
     * Validate and normalise one learner. Returns the columns to save and any errors (field => message).
     *
     * @param  array<string, mixed>  $in
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function check(array $in, ?Learner $existing): array
    {
        $errors = [];
        $clean = [];
        foreach (['first_names' => 'First names', 'surname' => 'Surname'] as $field => $label) {
            $value = trim((string) ($in[$field] ?? ''));
            if ($value === '' || mb_strlen($value) > 120) {
                $errors[$field] = "{$label} is required (up to 120 characters).";
            }
            $clean[$field] = $value;
        }

        $number = IdNumbers::normalise((string) ($in['id_number'] ?? ''));
        $type = (string) ($in['id_type'] ?? (preg_match('/^\d{13}$/', $number) === 1 ? 'sa_id' : 'passport'));
        $keepId = $existing !== null && $number === '';
        if (! $keepId) {
            if ($type === 'sa_id') {
                $parsed = SaIdNumber::parse($number);
                if ($parsed === null) {
                    $errors['id_number'] = 'This SA ID number is not valid. Check the 13 digits.';
                } else {
                    $clean += $parsed;
                }
            } elseif ($type === 'passport') {
                if (preg_match('/^[A-Z0-9]{6,20}$/', $number) !== 1) {
                    $errors['id_number'] = 'Enter the passport number (6 to 20 letters and digits).';
                }
                $clean['citizenship'] = 'foreign';
                $clean['gender'] = in_array($in['gender'] ?? null, ['female', 'male'], true) ? $in['gender'] : null;
                $clean['date_of_birth'] = isset($in['date_of_birth']) && strtotime((string) $in['date_of_birth']) ? date('Y-m-d', (int) strtotime((string) $in['date_of_birth'])) : null;
            } else {
                $errors['id_type'] = 'Choose SA ID or passport.';
            }
            if (! isset($errors['id_number']) && ! isset($errors['id_type'])) {
                $clean['id_type'] = $type;
                $clean += IdNumbers::protect($type, $number);
                $dupe = Learner::query()->where('id_number_hash', $clean['id_number_hash'])->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->first();
                if ($dupe !== null) {
                    $errors['id_number'] = "Already a learner: {$dupe->fullName()}.";
                }
            }
        }

        $phoneRaw = trim((string) ($in['phone'] ?? ''));
        $clean['phone'] = $phoneRaw === '' ? null : PhoneNumber::normalise($phoneRaw);
        if ($phoneRaw !== '' && $clean['phone'] === null) {
            $errors['phone'] = 'Enter a valid mobile number, for example 082 123 4567.';
        } elseif ($clean['phone'] !== null && Learner::query()->where('phone', $clean['phone'])->when($existing, fn ($q) => $q->whereKeyNot($existing->id))->exists()) {
            $errors['phone'] = 'Another learner already has this mobile number.';
        }

        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $clean['email'] = $email === '' ? null : $email;
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if ($clean['phone'] === null && $clean['email'] === null && $phoneRaw === '') {
            $errors['phone'] = 'Add a mobile number or an email address, so the learner can be invited.';
        }

        $lang = (string) ($in['home_language'] ?? '');
        if ($lang !== '' && ! in_array($lang, Learner::LANGUAGES, true)) {
            $errors['home_language'] = 'Choose a home language from the list.';
        }
        $clean['home_language'] = $lang === '' ? null : $lang;
        if (array_key_exists('qcto_learner_number', $in)) {
            $clean['qcto_learner_number'] = trim((string) $in['qcto_learner_number']) ?: null;
        }

        return [$clean, $errors];
    }

    /** @return array<string, mixed> */
    private function current(Learner $l): array
    {
        return ['first_names' => $l->first_names, 'surname' => $l->surname, 'phone' => $l->phone, 'email' => $l->email, 'home_language' => $l->home_language, 'id_type' => $l->id_type];
    }
}
