<?php

declare(strict_types=1);

namespace App\Programmes;

use App\Audit\AuditLogger;
use App\Models\Outcome;
use App\Models\OutcomeCoverage;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The coverage map: for every workplace outcome, whether a simulator and/or a course covers it.
 *
 * An outcome is "ready" when at least one channel is linked (live content a learner can use).
 * Readiness % = ready outcomes ÷ outcomes in the programme's current version. Coverage is keyed by the
 * stable outcome code, so it carries over to new versions; a removed outcome simply stops counting.
 */
final class CoverageMap
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Readiness for every programme (HQ list).
     *
     * @return array<string, array{outcomes: int, ready: int, planned: int, readiness: int}>
     */
    public function overview(): array
    {
        $versions = $this->currentVersions();
        $codes = Outcome::query()->whereIn('programme_version_id', $versions->values())->get(['programme_version_id', 'code'])
            ->groupBy('programme_version_id');
        $coverage = OutcomeCoverage::query()->get(['programme_id', 'outcome_code', 'status'])->groupBy('programme_id');
        $programmes = Programme::query()->whereIn('id', $versions->keys())->pluck('code', 'id');

        $result = [];
        foreach ($versions as $programmeId => $versionId) {
            $outcomes = $codes->get($versionId, collect())->pluck('code')->all();
            $rows = $coverage->get($programmeId, collect())->whereIn('outcome_code', $outcomes);
            $result[(string) $programmes[$programmeId]] = $this->score(count($outcomes), $rows);
        }

        return $result;
    }

    /**
     * Coverage per outcome for one programme (HQ coverage tab).
     *
     * @return array<string, mixed>
     */
    public function forProgramme(Programme $programme): array
    {
        $version = $this->currentVersion($programme);
        $outcomes = $version === null ? collect() : $version->outcomes()->get(['code']);
        $rows = OutcomeCoverage::query()->where('programme_id', $programme->id)->whereIn('outcome_code', $outcomes->pluck('code'))->get();

        $items = [];
        foreach ($rows as $row) {
            $items[$row->outcome_code][$row->channel] = ['status' => $row->status, 'title' => $row->title];
        }

        return [
            'version' => $version?->number,
            'summary' => $this->score($outcomes->count(), $rows),
            'outcomes' => (object) $items,
        ];
    }

    /** Set (or clear, with a null status) how one channel covers one outcome. */
    public function set(Programme $programme, string $code, string $channel, ?string $status, ?string $title, User $actor): void
    {
        $known = Outcome::query()->where('code', $code)
            ->whereIn('programme_version_id', $programme->versions()->whereIn('status', [ProgrammeVersion::PUBLISHED, ProgrammeVersion::DRAFT, ProgrammeVersion::IN_REVIEW])->select('id'))
            ->exists();
        if (! $known) {
            throw ValidationException::withMessages(['code' => 'This outcome is not in the programme.'])->status(404);
        }

        $existing = OutcomeCoverage::query()->where(['programme_id' => $programme->id, 'outcome_code' => $code, 'channel' => $channel])->first();
        $before = $existing?->only(['status', 'title']);

        if ($status === null) {
            $existing?->delete();
            $after = null;
        } else {
            $row = $existing ?? new OutcomeCoverage(['programme_id' => $programme->id, 'outcome_code' => $code, 'channel' => $channel]);
            $row->fill(['status' => $status, 'title' => $title, 'updated_by' => $actor->id])->save();
            $after = $row->only(['status', 'title']);
        }

        if ($before !== $after) {
            $this->context->runAs(null, fn () => $this->audit->record('programmes.coverage_changed', $programme, ['before' => $before, 'after' => $after], [
                'programme' => $programme->code, 'outcome' => $code, 'channel' => $channel,
            ], $actor), platform: true);
        }
    }

    /**
     * @param  Collection<int, OutcomeCoverage>  $rows
     * @return array{outcomes: int, ready: int, planned: int, readiness: int}
     */
    private function score(int $outcomes, Collection $rows): array
    {
        $byCode = $rows->groupBy('outcome_code');
        $ready = $byCode->filter(fn (Collection $r) => $r->contains('status', 'linked'))->count();

        return [
            'outcomes' => $outcomes,
            'ready' => $ready,
            'planned' => $byCode->count() - $ready,
            'readiness' => $outcomes === 0 ? 0 : (int) floor($ready * 100 / $outcomes),
        ];
    }

    /** The version coverage is measured against: the published one, else the newest. */
    private function currentVersion(Programme $programme): ?ProgrammeVersion
    {
        return $programme->published()->first() ?? $programme->versions()->first();
    }

    /** @return Collection<string, string> programme id → version id */
    private function currentVersions(): Collection
    {
        $all = ProgrammeVersion::query()->orderByDesc('number')->get(['id', 'programme_id', 'number', 'status']);

        /** @var Collection<string, string> */
        return $all->groupBy('programme_id')->map(function (Collection $versions): string {
            $chosen = $versions->firstWhere('status', ProgrammeVersion::PUBLISHED) ?? $versions->first();

            return (string) $chosen->id;
        });
    }
}
