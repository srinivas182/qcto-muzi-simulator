<?php

declare(strict_types=1);

namespace Tests\Feature\Programmes;

use App\Models\Programme;
use App\Programmes\GuideImporter;
use App\Programmes\ProgrammeVersions;
use App\Tenancy\TenantContext;
use Tests\Feature\Access\AccessTestCase;

final class CatalogueApiTest extends AccessTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(GuideImporter::class)->import(publish: true);
        app(TenantContext::class)->clear();
    }

    public function test_providers_see_the_published_catalogue(): void
    {
        [, $token] = $this->person($this->ubuntu, 'facilitator');

        $list = $this->getJson($this->url('/programmes'), $this->bearer($token))->assertOk()->json('data');

        $this->assertCount(13, $list);
        $this->assertSame('technical-support', $list[0]['code']);
        $this->assertSame(['duty_areas' => 6, 'outcomes' => 24, 'evidence' => 15], $list[0]['counts']);
    }

    public function test_providers_read_a_programme_in_full(): void
    {
        [, $token] = $this->person($this->ubuntu, 'assessor');

        $this->getJson($this->url('/programmes/technical-support'), $this->bearer($token))->assertOk()
            ->assertJsonPath('data.programme.title', 'Technical Support')
            ->assertJsonPath('data.duty_areas.0.outcomes.0.code', 'TS-1.1')
            ->assertJsonPath('data.qualifications.1.saqa_id', '101408')
            ->assertJsonPath('data.status', 'published');
    }

    public function test_drafts_never_reach_providers(): void
    {
        $programme = Programme::query()->create(['code' => 'new-thing', 'prefix' => 'NEW', 'title' => 'New programme', 'family' => 'Test', 'position' => 99]);
        $data = json_decode((string) file_get_contents(database_path(GuideImporter::DEFAULT_FILE)), true);
        $data['programmes'][0]['duty_areas'] = [];
        app(ProgrammeVersions::class)->createDraft($programme, $data['programmes'][0], ['document' => 'test']);
        [, $token] = $this->person($this->ubuntu, 'tp_admin');

        $this->getJson($this->url('/programmes/new-thing'), $this->bearer($token))->assertNotFound();
        $this->assertNotContains('new-thing', array_column($this->getJson($this->url('/programmes'), $this->bearer($token))->json('data'), 'code'));
    }

    public function test_learners_do_not_browse_the_catalogue(): void
    {
        [, $token] = $this->person($this->ubuntu, 'learner');

        $this->getJson($this->url('/programmes'), $this->bearer($token))->assertForbidden();
    }

    public function test_hq_sees_drafts_and_every_version(): void
    {
        [, $token] = $this->person(null, 'scenario_designer', ['email' => 'lwazi@mayuraconsultancy.com']);
        $programme = Programme::query()->where('code', 'robotics')->firstOrFail();
        $data = json_decode((string) file_get_contents(database_path(GuideImporter::DEFAULT_FILE)), true);
        app(ProgrammeVersions::class)->createDraft($programme, $data['programmes'][4], ['document' => 'test'], notes: 'Add PLC safety outcome');

        $robotics = collect($this->getJson($this->url('/platform/programmes', self::HQ), $this->bearer($token))->assertOk()->json('data'))->firstWhere('code', 'robotics');
        $this->assertSame(['version' => 2, 'status' => 'draft'], $robotics['draft']);
        $this->assertSame(1, $robotics['version']);

        $this->getJson($this->url('/platform/programmes/robotics', self::HQ), $this->bearer($token))->assertOk()
            ->assertJsonPath('data.current.status', 'draft')
            ->assertJsonPath('data.versions.0.change_notes', 'Add PLC safety outcome')
            ->assertJsonCount(2, 'data.versions');
        $this->getJson($this->url('/platform/programmes/robotics/versions/1', self::HQ), $this->bearer($token))->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function test_hq_staff_without_programme_access_are_refused(): void
    {
        [, $token] = $this->person(null, 'support_finance', ['email' => 'thabo@mayuraconsultancy.com']);

        $this->getJson($this->url('/platform/programmes', self::HQ), $this->bearer($token))->assertForbidden();
    }

    public function test_reference_pages_are_available_to_providers(): void
    {
        [, $token] = $this->person($this->ubuntu, 'facilitator');

        $pages = $this->getJson($this->url('/reference-pages'), $this->bearer($token))->assertOk()->json('data');
        $this->assertSame(['the-framework-saqa-qcto-and-services-seta', 'the-workplace-mentor-s-guide', 'qualification-and-programme-profiles'], array_column($pages, 'slug'));

        $this->getJson($this->url('/reference-pages/the-workplace-mentor-s-guide'), $this->bearer($token))->assertOk()
            ->assertJsonPath('data.title', "The workplace mentor's guide");
    }
}
