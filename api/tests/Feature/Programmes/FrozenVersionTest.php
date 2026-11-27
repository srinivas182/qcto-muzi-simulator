<?php

declare(strict_types=1);

namespace Tests\Feature\Programmes;

use App\Models\DutyArea;
use App\Models\Outcome;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Programmes\GuideImporter;
use App\Programmes\ProgrammeVersions;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Cohorts keep exactly the content they enrolled under: published versions cannot change. */
final class FrozenVersionTest extends TestCase
{
    use RefreshDatabase;

    private ProgrammeVersion $published;

    protected function setUp(): void
    {
        parent::setUp();
        app(GuideImporter::class)->import(publish: true);
        $this->published = Programme::query()->where('code', 'technical-support')->firstOrFail()->published()->firstOrFail();
    }

    private function refused(callable $change): void
    {
        try {
            DB::transaction($change);
            $this->fail('A frozen programme version was changed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage());
        }
    }

    public function test_published_content_cannot_change(): void
    {
        $this->refused(fn () => $this->published->update(['purpose' => 'Something else']));
        $this->refused(fn () => Outcome::query()->where('programme_version_id', $this->published->id)->limit(1)->update(['statement' => 'Changed']));
        $this->refused(fn () => DutyArea::query()->where('programme_version_id', $this->published->id)->delete());
        $this->refused(fn () => $this->published->dutyAreas()->create(['number' => 99, 'title' => 'Sneaky']));
        $this->refused(fn () => $this->published->delete());
    }

    public function test_publishing_a_new_version_retires_the_old_one_unchanged(): void
    {
        $versions = app(ProgrammeVersions::class);
        $data = json_decode((string) file_get_contents(database_path(GuideImporter::DEFAULT_FILE)), true);
        $draft = $versions->createDraft($this->published->programme, $data['programmes'][0], ['document' => 'test']);
        $draft->update(['purpose' => 'Edited while still a draft']);

        $versions->publish($draft);

        $this->assertSame('retired', $this->published->fresh()?->status);
        $this->assertSame('published', $draft->fresh()?->status);
        $this->assertSame(2, $draft->number);
        $this->refused(fn () => $this->published->fresh()?->update(['status' => 'published']));
    }

    public function test_only_one_published_version_per_programme(): void
    {
        $data = json_decode((string) file_get_contents(database_path(GuideImporter::DEFAULT_FILE)), true);
        $draft = app(ProgrammeVersions::class)->createDraft($this->published->programme, $data['programmes'][0], ['document' => 'test']);

        $this->expectException(QueryException::class);
        $draft->update(['status' => 'published']);
    }
}
