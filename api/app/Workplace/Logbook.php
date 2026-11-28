<?php

declare(strict_types=1);

namespace App\Workplace;

use App\Audit\AuditLogger;
use App\Identity\PhoneNumber;
use App\Models\AssessmentTask;
use App\Models\Enrolment;
use App\Models\LogbookEntry;
use App\Models\Outcome;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The workplace logbook (Sprint 5.3): the placement, and the learner's daily check-in against the workplace
 * outcomes. Check-ins are safe to send again (client id), may be up to 14 days late (no signal on site), and
 * a day the mentor has confirmed cannot be changed.
 */
final class Logbook
{
    public const LATE_DAYS = 14;

    public const PHOTO_BYTES = 153_600; // 150 KB

    public function __construct(private readonly AuditLogger $audit) {}

    public static function current(Enrolment $e): ?Workplace
    {
        return Workplace::query()->where('enrolment_id', $e->id)->latest('starts_on')->first();
    }

    /** The logbook task of the learner's programme (minimum days, outcomes), if HQ has published one. */
    public static function task(Enrolment $e): ?AssessmentTask
    {
        return AssessmentTask::query()->where('programme_version_id', $e->programme_version_id)->where('kind', 'logbook')->where('status', 'published')->orderBy('position')->first();
    }

    /**
     * The outcomes a learner can log against: the logbook task's, or else all of the programme's.
     *
     * @return list<array{code: string, statement: string}>
     */
    public static function outcomes(Enrolment $e): array
    {
        $task = self::task($e);
        $codes = $task?->outcomeCodes() ?? [];

        return Outcome::query()->where('programme_version_id', $e->programme_version_id)->when($codes !== [], fn ($q) => $q->whereIn('code', $codes))
            ->orderBy('position')->get(['code', 'statement', 'plain_words'])
            ->map(fn (Outcome $o) => ['code' => $o->code, 'statement' => (string) ($o->plain_words ?: $o->statement)])->values()->all();
    }

    /**
     * Set or change the placement.
     *
     * @param  array{employer: string, site?: string|null, mentor_name: string, mentor_phone: string, confirm_by?: string, mentor_user_id?: string|null, starts_on: string, ends_on?: string|null}  $input
     */
    public function place(Enrolment $e, array $input, User $actor): Workplace
    {
        $phone = PhoneNumber::normalise($input['mentor_phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['mentor_phone' => 'Enter the mentor’s cell number.']);
        }
        $by = ($input['confirm_by'] ?? 'link') === 'account' ? 'account' : 'link';
        if ($by === 'account' && empty($input['mentor_user_id'])) {
            throw ValidationException::withMessages(['mentor_user_id' => 'Choose the mentor’s account, or let them confirm by link.']);
        }
        $values = ['employer' => trim($input['employer']), 'site' => ($input['site'] ?? null) ?: null, 'mentor_name' => trim($input['mentor_name']), 'mentor_phone' => $phone,
            'confirm_by' => $by, 'mentor_user_id' => $by === 'account' ? $input['mentor_user_id'] : null, 'starts_on' => $input['starts_on'], 'ends_on' => ($input['ends_on'] ?? null) ?: null];
        $w = self::current($e);
        $w === null ? $w = Workplace::query()->create($values + ['enrolment_id' => $e->id, 'created_by' => $actor->id]) : $w->update($values);
        $this->audit->record('workplace.placed', $w, null, ['employer' => $w->employer, 'confirm_by' => $by], $actor);

        return $w;
    }

    /**
     * A learner's check-in for one day. Sending the same client id again updates nothing new.
     *
     * @param  array{client_id: string, day: string, hours: float|int|string, outcomes?: list<string>, note?: string|null}  $input
     */
    public function checkIn(Enrolment $e, array $input, User $learner): LogbookEntry
    {
        $w = self::current($e) ?? throw ValidationException::withMessages(['workplace' => 'Your provider has not added your workplace yet.']);
        $day = Carbon::parse($input['day'])->startOfDay();
        $today = now()->startOfDay();
        if ($day->gt($today) || $day->lt($today->copy()->subDays(self::LATE_DAYS)) || $day->lt($w->starts_on) || ($w->ends_on !== null && $day->gt($w->ends_on))) {
            throw ValidationException::withMessages(['day' => 'Choose a day at your workplace in the last '.self::LATE_DAYS.' days.']);
        }
        $hours = round((float) $input['hours'] * 2) / 2;
        if ($hours < 0.5 || $hours > 12) {
            throw ValidationException::withMessages(['hours' => 'Hours must be between half an hour and 12.']);
        }
        $allowed = array_column(self::outcomes($e), 'code');
        $outcomes = array_values(array_unique(array_intersect(array_map('strval', $input['outcomes'] ?? []), $allowed)));
        $note = trim((string) ($input['note'] ?? ''));

        return DB::transaction(function () use ($e, $w, $input, $day, $hours, $outcomes, $note, $learner): LogbookEntry {
            $same = LogbookEntry::query()->where('client_id', $input['client_id'])->first();
            if ($same !== null) {
                return $same->enrolment_id === $e->id ? $same : throw ValidationException::withMessages(['client_id' => 'Already used.']);
            }
            $entry = LogbookEntry::query()->where('enrolment_id', $e->id)->whereDate('day', $day)->lockForUpdate()->first();
            if ($entry?->status === 'confirmed') {
                throw ValidationException::withMessages(['day' => 'Your mentor has already confirmed this day.']);
            }
            $values = ['workplace_id' => $w->id, 'hours' => $hours, 'outcomes' => $outcomes, 'note' => $note === '' ? null : mb_substr($note, 0, 500), 'status' => 'submitted', 'mentor_note' => null];
            if ($entry === null) {
                $entry = LogbookEntry::query()->create($values + ['enrolment_id' => $e->id, 'client_id' => $input['client_id'], 'day' => $day->toDateString()]);
            } else {
                $entry->update($values + ['client_id' => $input['client_id']]); // a corrected day after a query
            }
            $this->audit->record('workplace.checked_in', $entry, null, ['day' => $day->toDateString(), 'hours' => $hours], $learner);

            return $entry;
        });
    }

    /** One small photo per day, kept privately. */
    public function photo(LogbookEntry $entry, UploadedFile $file): LogbookEntry
    {
        $mime = (string) $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/webp'], true) || (int) $file->getSize() > self::PHOTO_BYTES) {
            throw ValidationException::withMessages(['photo' => 'Send a JPEG or WebP photo of 150 KB or less.']);
        }
        if ($entry->status === 'confirmed') {
            throw ValidationException::withMessages(['photo' => 'This day is already confirmed.']);
        }
        $old = $entry->photo_path;
        $path = $file->storeAs("logbook/{$entry->tenant_id}/{$entry->enrolment_id}", $entry->day->format('Y-m-d').'-'.Str::lower(Str::random(8)).($mime === 'image/webp' ? '.webp' : '.jpg'), 'local');
        $entry->update(['photo_path' => $path, 'photo_bytes' => (int) $file->getSize()]);
        $old !== null && Storage::disk('local')->delete($old);

        return $entry;
    }

    /**
     * Where the learner stands: days and hours logged and confirmed, and how often each outcome was practised.
     *
     * @return array{days: int, confirmed_days: int, hours: float, confirmed_hours: float, min_days: int|null, outcomes: array<string, int>}
     */
    public static function summary(Enrolment $e): array
    {
        $entries = LogbookEntry::query()->where('enrolment_id', $e->id)->get(['status', 'hours', 'outcomes']);
        $confirmed = $entries->where('status', 'confirmed');
        $counts = [];
        foreach ($confirmed as $x) {
            foreach ($x->outcomes as $code) {
                $counts[$code] = ($counts[$code] ?? 0) + 1;
            }
        }
        $min = self::task($e)?->settings['min_days'] ?? null;

        return ['days' => $entries->count(), 'confirmed_days' => $confirmed->count(), 'hours' => (float) $entries->sum('hours'), 'confirmed_hours' => (float) $confirmed->sum('hours'),
            'min_days' => $min === null ? null : (int) $min, 'outcomes' => $counts];
    }

    /** @return array<string, mixed> */
    public static function entryOut(LogbookEntry $x): array
    {
        return ['id' => $x->id, 'client_id' => $x->client_id, 'day' => $x->day->toDateString(), 'hours' => $x->hours, 'outcomes' => $x->outcomes, 'note' => $x->note,
            'photo' => $x->photo_path !== null, 'status' => $x->status, 'mentor_note' => $x->mentor_note, 'confirmed_at' => $x->confirmed_at?->toIso8601String(), 'confirmed_name' => $x->confirmed_name];
    }

    /** @return array<string, mixed>|null */
    public static function workplaceOut(?Workplace $w, bool $staff = false): ?array
    {
        if ($w === null) {
            return null;
        }
        $out = ['id' => $w->id, 'employer' => $w->employer, 'site' => $w->site, 'mentor_name' => $w->mentor_name, 'confirm_by' => $w->confirm_by,
            'starts_on' => $w->starts_on->toDateString(), 'ends_on' => $w->ends_on?->toDateString()];

        return $staff ? $out + ['mentor_phone' => $w->mentor_phone, 'mentor_user_id' => $w->mentor_user_id] : $out;
    }
}
