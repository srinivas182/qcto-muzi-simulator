<?php

declare(strict_types=1);

namespace App\Cohorts;

use App\Audit\AuditLogger;
use App\Billing\LicenceOrders;
use App\Models\Cohort;
use App\Models\Enrolment;
use App\Models\Learner;
use App\Models\LicencePool;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Enrolling learners into cohorts, and the seat rules (decision 2).
 *
 * Every enrolment takes one seat from an active licence pool for the cohort's programme. Pools are locked while
 * seats are counted, and a database trigger refuses to overfill a pool even if two admins enrol at once.
 */
final class Enrolments
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Enrol learners into a cohort. All or nothing on seats: if there are not enough free seats for everyone
     * who can be enrolled, nobody is enrolled and the message says how many seats are missing.
     *
     * @param  list<string>  $learnerIds
     * @return array{enrolled: list<Enrolment>, skipped: list<array{learner_id: string, name: string, reason: string}>}
     */
    public function enrol(Tenant $tenant, Cohort $cohort, array $learnerIds, User $actor): array
    {
        if (LicenceOrders::isBlocked($tenant)) {
            throw new HttpException(423, 'An invoice is more than 30 days overdue. Pay it to enrol new learners; learners already enrolled keep learning.');
        }
        if ($cohort->archived_at !== null) {
            throw ValidationException::withMessages(['cohort' => 'This cohort is archived.']);
        }

        $this->counts = [];

        return DB::transaction(function () use ($cohort, $learnerIds, $actor): array {
            $learners = Learner::query()->whereIn('id', array_unique($learnerIds))->get()->keyBy('id');
            $current = Enrolment::query()->whereIn('learner_id', $learners->keys())->where('programme_id', $cohort->programme_id)
                ->whereIn('status', Enrolment::CURRENT)->pluck('learner_id')->flip();
            $skipped = [];
            $ready = [];
            foreach (array_unique($learnerIds) as $id) {
                $learner = $learners->get($id);
                $reason = match (true) {
                    $learner === null => 'Not one of your learners.',
                    $learner->status === Learner::INACTIVE => 'This learner is deactivated.',
                    $current->has($id) => 'Already enrolled on this programme.',
                    default => null,
                };
                if ($reason !== null) {
                    $skipped[] = ['learner_id' => $id, 'name' => $learner?->fullName() ?? '', 'reason' => $reason];
                } else {
                    $ready[] = $learner;
                }
            }
            if ($ready === []) {
                return ['enrolled' => [], 'skipped' => $skipped];
            }

            $pools = $this->openPools($cohort->programme_id, lock: true);
            $free = $pools->sum(fn (LicencePool $p) => $p->seats - $this->taken($p));
            if ($free < count($ready)) {
                $short = count($ready) - $free;
                throw ValidationException::withMessages(['learners' => $pools->isEmpty()
                    ? 'You have no active licence for this programme. Buy seats in Licences & packages first.'
                    : "Only {$free} seats are free for this programme; you picked ".count($ready).". Buy {$short} more seats or pick fewer learners."]);
            }

            $enrolled = [];
            foreach ($ready as $learner) {
                /** @var LicencePool $pool there are enough free seats, checked above */
                $pool = $pools->first(fn (LicencePool $p) => $this->taken($p) < $p->seats);
                try {
                    $enrolled[] = Enrolment::query()->create([
                        'learner_id' => $learner->id, 'cohort_id' => $cohort->id, 'programme_id' => $cohort->programme_id,
                        'programme_version_id' => $cohort->programme_version_id, 'licence_pool_id' => $pool->id,
                        'seat_taken_at' => now(), 'created_by' => $actor->id,
                    ]);
                } catch (QueryException $e) {
                    throw str_contains($e->getMessage(), 'no free seats') || str_contains($e->getMessage(), 'not active')
                        ? ValidationException::withMessages(['learners' => 'The seats were just taken by someone else. Refresh and try again.'])
                        : $e;
                }
                unset($this->counts[$pool->id]);
            }
            $this->audit->record('enrolments.created', $cohort, null, ['count' => count($enrolled)], $actor);

            return ['enrolled' => $enrolled, 'skipped' => $skipped];
        });
    }

    /** Withdraw a learner. Before 10% progress the seat goes back to the pool; after that it stays used. */
    public function withdraw(Enrolment $enrolment, string $reason, User $actor): Enrolment
    {
        $this->assertCurrent($enrolment);
        $returns = $enrolment->progress < Enrolment::FREE_WITHDRAWAL_BELOW;
        $enrolment->update(['status' => Enrolment::WITHDRAWN, 'ended_at' => now(), 'reason' => $reason, 'holds_seat' => ! $returns]);
        $this->audit->record('enrolments.withdrawn', $enrolment, null, ['seat_returned' => $returns, 'progress' => $enrolment->progress], $actor);

        return $enrolment;
    }

    /** Move a learner to another cohort on the same programme. The seat moves with them. */
    public function transfer(Enrolment $enrolment, Cohort $to, User $actor): Enrolment
    {
        $this->assertCurrent($enrolment);
        if ($to->programme_id !== $enrolment->programme_id || $to->archived_at !== null || $to->id === $enrolment->cohort_id) {
            throw ValidationException::withMessages(['cohort_id' => 'Choose another open cohort on the same programme.']);
        }
        $from = $enrolment->cohort_id;
        $enrolment->update(['cohort_id' => $to->id, 'programme_version_id' => $to->programme_version_id]);
        $this->audit->record('enrolments.transferred', $enrolment, null, ['from' => $from, 'to' => $to->id], $actor);

        return $enrolment;
    }

    /** The learner opened their programme: the seat is now in use and the no-show rule no longer applies. */
    public static function markStarted(Enrolment $enrolment): void
    {
        if ($enrolment->started_at === null && $enrolment->status === Enrolment::ENROLLED) {
            $enrolment->update(['started_at' => now(), 'status' => Enrolment::ACTIVE]);
        }
    }

    /** Daily: give back the seats of learners who never started. Runs inside one provider's context. */
    public function returnNoShows(): int
    {
        $n = 0;
        $due = Enrolment::query()->with('cohort')->where('status', Enrolment::ENROLLED)->whereNull('started_at')->where('holds_seat', true)
            ->where('seat_taken_at', '<=', now()->subDays(Enrolment::NO_SHOW_DAYS))->get();
        foreach ($due as $e) {
            if ($e->noShowDate($e->cohort)->isAfter(today())) {
                continue;
            }
            $e->update(['status' => Enrolment::NO_SHOW, 'holds_seat' => false, 'ended_at' => now(), 'reason' => 'Did not start within '.Enrolment::NO_SHOW_DAYS.' days.']);
            $this->audit->record('enrolments.no_show', $e, null, ['seat_returned' => true]);
            $n++;
        }

        return $n;
    }

    /**
     * Active pools for a programme that have not ended, the one ending first used first.
     *
     * @return Collection<int, LicencePool>
     */
    public function openPools(string $programmeId, bool $lock = false): Collection
    {
        return LicencePool::query()->where('programme_id', $programmeId)->where('status', LicencePool::ACTIVE)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', today()))
            ->orderByRaw('ends_on asc nulls last')->orderBy('created_at')
            ->when($lock, fn ($q) => $q->lockForUpdate())->get();
    }

    /** @var array<string, int> */
    private array $counts = [];

    private function taken(LicencePool $pool): int
    {
        return $this->counts[$pool->id] ??= Enrolment::query()->where('licence_pool_id', $pool->id)->where('holds_seat', true)->count();
    }

    private function assertCurrent(Enrolment $enrolment): void
    {
        if (! in_array($enrolment->status, Enrolment::CURRENT, true)) {
            throw ValidationException::withMessages(['enrolment' => 'This enrolment has already ended.']);
        }
    }
}
