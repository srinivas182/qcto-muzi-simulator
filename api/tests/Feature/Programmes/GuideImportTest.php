<?php

declare(strict_types=1);

namespace Tests\Feature\Programmes;

use App\Models\DutyArea;
use App\Models\EvidenceRequirement;
use App\Models\Outcome;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\QualificationRef;
use App\Models\ReferencePage;
use App\Programmes\GuideImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The Services SETA guide, loaded into the catalogue. Counts are checked against the guide itself. */
final class GuideImportTest extends TestCase
{
    use RefreshDatabase;

    /** Duty areas and activities per programme, counted in the guide (pages 7–39). */
    private const GUIDE = [
        'technical-support' => [6, 24], 'systems-support' => [7, 27], 'systems-development' => [7, 24],
        'business-process-outsourcing' => [7, 24], 'robotics' => [7, 24], 'data-analytics' => [7, 24],
        'cellphone-repair' => [7, 27], 'laptop-pc-repair' => [7, 25], 'drone-piloting-repairs' => [7, 25],
        'technopreneur' => [7, 25], 'cybersecurity-defender' => [7, 24],
        'end-user-computing-intermediate' => [7, 24], 'end-user-computing-advanced' => [7, 23],
    ];

    public function test_all_thirteen_programmes_load_with_every_duty_area_outcome_and_evidence_item(): void
    {
        $result = app(GuideImporter::class)->import(publish: true);

        $this->assertCount(13, $result['created']);
        $this->assertSame(13, Programme::query()->count());
        $this->assertSame(90, DutyArea::query()->count());
        $this->assertSame(320, Outcome::query()->count());
        $this->assertSame(229, EvidenceRequirement::query()->count());
        $this->assertSame(3, ReferencePage::query()->count());

        foreach (self::GUIDE as $code => [$areas, $outcomes]) {
            $version = Programme::query()->where('code', $code)->firstOrFail()->published()->firstOrFail();
            $this->assertSame($areas, $version->dutyAreas()->count(), "{$code} duty areas");
            $this->assertSame($outcomes, $version->outcomes()->count(), "{$code} outcomes");
            $this->assertCount(6, $version->tools, "{$code} tools");
            $this->assertCount(3, $version->workplace_requirements, "{$code} workplace requirements");
        }
    }

    public function test_content_matches_the_guide_wording(): void
    {
        app(GuideImporter::class)->import(publish: true);
        $ts = Programme::query()->where('code', 'technical-support')->firstOrFail()->published()->firstOrFail();

        $this->assertSame('First-line and second-line support of users, devices, software and basic networks.', $ts->summary);
        $this->assertSame('Service desk and ticket management', $ts->dutyAreas()->first()?->title);
        $this->assertSame(
            'Receive requests by phone, email, walk-in or portal and log each one as a ticket with accurate details.',
            Outcome::query()->where('programme_version_id', $ts->id)->where('code', 'TS-1.1')->value('statement'),
        );
        $this->assertSame('photo', EvidenceRequirement::query()->where('description', 'like', 'Photos of completed installations%')->value('type'));
        $this->assertSame(7, $ts->source['page']);
    }

    public function test_legacy_and_current_qcto_qualifications_are_both_recorded(): void
    {
        app(GuideImporter::class)->import(publish: true);
        $refs = QualificationRef::query()->whereIn('saqa_id', ['78964', '101408'])->get()->keyBy('saqa_id');

        $this->assertSame('legacy', $refs['78964']->kind);
        $this->assertSame(4, $refs['78964']->nqf_level);
        $this->assertSame('occupational', $refs['101408']->kind);
        $this->assertSame('Occupational Certificate: Computer Technician', $refs['101408']->title);
        $this->assertSame(282, $refs['101408']->credits);
    }

    public function test_outcome_codes_are_unique_and_follow_the_pattern(): void
    {
        app(GuideImporter::class)->import(publish: true);

        foreach (Outcome::query()->pluck('code') as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]+(-[A-Z])?-\d+\.\d+$/', (string) $code);
        }
        $this->assertSame(320, Outcome::query()->distinct()->count('code'));
    }

    public function test_import_is_repeatable(): void
    {
        app(GuideImporter::class)->import(publish: true);
        $second = app(GuideImporter::class)->import(publish: true);

        $this->assertCount(13, $second['unchanged']);
        $this->assertSame(13, ProgrammeVersion::query()->count());
    }

    public function test_a_draft_being_edited_is_never_overwritten(): void
    {
        app(GuideImporter::class)->import(publish: false);
        $file = tempnam(sys_get_temp_dir(), 'guide');
        $data = json_decode((string) file_get_contents(database_path(GuideImporter::DEFAULT_FILE)), true);
        $data['programmes'][0]['checksum'] = 'changed';
        file_put_contents((string) $file, json_encode($data));

        $result = app(GuideImporter::class)->import($file, publish: true);

        $this->assertContains('technical-support', $result['skipped']);
        $this->assertSame(13, ProgrammeVersion::query()->count());
    }

    public function test_the_command_loads_the_catalogue(): void
    {
        $this->artisan('programmes:import', ['--publish' => true])->assertSuccessful();

        $this->assertSame(13, ProgrammeVersion::query()->where('status', 'published')->count());
    }
}
