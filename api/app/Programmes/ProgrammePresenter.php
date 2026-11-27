<?php

declare(strict_types=1);

namespace App\Programmes;

use App\Models\DutyArea;
use App\Models\EvidenceRequirement;
use App\Models\Outcome;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\QualificationRef;

/** JSON shapes for programmes and versions (shared by the HQ and provider APIs). */
final class ProgrammePresenter
{
    /** @return array<string, mixed> */
    public static function summary(Programme $programme, ?ProgrammeVersion $version): array
    {
        return [
            'code' => $programme->code,
            'prefix' => $programme->prefix,
            'title' => $programme->title,
            'family' => $programme->family,
            'summary' => $version?->summary,
            'version' => $version?->number,
            'published_at' => $version?->published_at?->toIso8601String(),
            'counts' => $version ? [
                'duty_areas' => (int) ($version->getAttribute('duty_areas_count') ?? $version->dutyAreas()->count()),
                'outcomes' => (int) ($version->getAttribute('outcomes_count') ?? $version->outcomes()->count()),
                'evidence' => (int) ($version->getAttribute('evidence_count') ?? $version->evidence()->count()),
            ] : null,
        ];
    }

    /**
     * Full content of one version.
     *
     * @return array<string, mixed>
     */
    public static function version(ProgrammeVersion $version): array
    {
        $version->loadMissing(['programme', 'qualifications', 'dutyAreas.outcomes', 'dutyAreas.evidence']);

        return [
            'programme' => ['code' => $version->programme->code, 'prefix' => $version->programme->prefix, 'title' => $version->programme->title, 'family' => $version->programme->family],
            'version' => $version->number,
            'status' => $version->status,
            'summary' => $version->summary,
            'purpose' => $version->purpose,
            'occupations' => $version->occupations,
            'workplace_requirements' => $version->workplace_requirements,
            'tools' => $version->tools,
            'to_confirm' => $version->to_confirm,
            'source' => $version->source,
            'change_notes' => $version->change_notes,
            'published_at' => $version->published_at?->toIso8601String(),
            'qualifications' => $version->qualifications->map(fn (QualificationRef $q) => [
                'kind' => $q->kind, 'saqa_id' => $q->saqa_id, 'title' => $q->title, 'nqf_level' => $q->nqf_level,
                'credits' => $q->credits, 'source_text' => $q->source_text,
            ])->all(),
            'duty_areas' => $version->dutyAreas->map(fn (DutyArea $d) => [
                'number' => $d->number,
                'title' => $d->title,
                'outcomes' => $d->outcomes->map(fn (Outcome $o) => ['code' => $o->code, 'statement' => $o->statement, 'plain_words' => $o->plain_words])->all(),
                'evidence' => $d->evidence->map(fn (EvidenceRequirement $e) => ['description' => $e->description, 'type' => $e->type, 'min_count' => $e->min_count])->all(),
            ])->all(),
        ];
    }
}
