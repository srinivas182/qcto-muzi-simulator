<?php

declare(strict_types=1);

namespace App\Programmes;

use App\Audit\AuditLogger;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Creates and publishes programme versions. (Review workflow and editing arrive in Sprint 2.2.) */
final class ProgrammeVersions
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ProgrammeCatalogue $catalogue,
        private readonly TenantContext $context,
    ) {}

    /**
     * Create a draft version from structured content (same shape as the guide JSON).
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $source
     */
    public function createDraft(Programme $programme, array $content, array $source, ?User $actor = null, ?string $notes = null): ProgrammeVersion
    {
        return DB::transaction(function () use ($programme, $content, $source, $actor, $notes): ProgrammeVersion {
            $number = (int) ProgrammeVersion::query()->where('programme_id', $programme->id)->max('number') + 1;

            $version = ProgrammeVersion::query()->create([
                'programme_id' => $programme->id,
                'number' => $number,
                'status' => ProgrammeVersion::DRAFT,
                'summary' => $content['summary'],
                'purpose' => $content['purpose'],
                'occupations' => $content['occupations'],
                'workplace_requirements' => $content['workplace_requirements'],
                'tools' => $content['tools'],
                'to_confirm' => $content['to_confirm'],
                'source' => $source,
                'change_notes' => $notes,
                'created_by' => $actor?->id,
            ]);

            /** @var list<array<string, mixed>> $qualifications */
            $qualifications = $content['qualifications'];
            foreach ($qualifications as $i => $q) {
                $version->qualifications()->create([
                    'position' => $i + 1, 'kind' => $q['kind'], 'saqa_id' => $q['saqa_id'], 'title' => $q['title'],
                    'nqf_level' => $q['nqf_level'], 'credits' => $q['credits'], 'source_text' => $q['source_text'],
                ]);
            }

            /** @var list<array{number: int, title: string, outcomes: list<array{code: string, statement: string, plain_words?: string|null}>, evidence: list<array{description: string, type: string, min_count?: int}>}> $dutyAreas */
            $dutyAreas = $content['duty_areas'];
            foreach ($dutyAreas as $d) {
                $area = $version->dutyAreas()->create(['number' => $d['number'], 'title' => $d['title']]);
                foreach ($d['outcomes'] as $i => $o) {
                    $area->outcomes()->create([
                        'programme_version_id' => $version->id, 'code' => $o['code'], 'position' => $i + 1,
                        'statement' => $o['statement'], 'plain_words' => $o['plain_words'] ?? null,
                    ]);
                }
                foreach ($d['evidence'] as $i => $e) {
                    $area->evidence()->create([
                        'programme_version_id' => $version->id, 'position' => $i + 1, 'description' => $e['description'],
                        'type' => $e['type'], 'min_count' => $e['min_count'] ?? 1,
                    ]);
                }
            }

            return $version;
        });
    }

    /** Publish a draft: the previous published version is retired; cohorts on it keep it. */
    public function publish(ProgrammeVersion $version, ?User $actor = null): ProgrammeVersion
    {
        DB::transaction(function () use ($version, $actor): void {
            ProgrammeVersion::query()
                ->where('programme_id', $version->programme_id)
                ->where('status', ProgrammeVersion::PUBLISHED)
                ->get()
                ->each(fn (ProgrammeVersion $old) => $old->update(['status' => ProgrammeVersion::RETIRED, 'retired_at' => now()]));

            $version->update(['status' => ProgrammeVersion::PUBLISHED, 'published_by' => $actor?->id, 'published_at' => now()]);
        });

        $this->catalogue->forgetList();
        // Catalogue changes are HQ events: recorded in the platform part of the audit log.
        $this->context->runAs(null, fn () => $this->audit->record('programmes.published', $version, null, ['programme' => $version->programme->code, 'version' => $version->number], $actor), platform: true);

        return $version;
    }
}
