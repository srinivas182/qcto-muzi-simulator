<?php

declare(strict_types=1);

namespace App\Programmes;

use App\Audit\AuditLogger;
use App\Models\Programme;
use App\Models\ProgrammeVersion;
use App\Models\ReferencePage;
use App\Models\User;
use App\Tenancy\TenantContext;
use RuntimeException;

/**
 * Loads the structured Workplace Practical Expectations guide (database/data/guide/*.json).
 * Repeatable: a programme whose latest version already has the same checksum is left alone, and a
 * programme with an open draft (HQ is editing it) is never overwritten.
 */
final class GuideImporter
{
    public const DEFAULT_FILE = 'data/guide/workplace-practical-expectations.json';

    public function __construct(
        private readonly ProgrammeVersions $versions,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    /** @return array{created: list<string>, unchanged: list<string>, skipped: list<string>, pages: int} */
    public function import(?string $path = null, bool $publish = false, ?User $actor = null): array
    {
        $path ??= database_path(self::DEFAULT_FILE);
        $raw = is_file($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new RuntimeException("Guide file not found: {$path}");
        }
        /** @var array{source: array<string, mixed>, family: string, programmes: list<array<string, mixed>>, reference_pages: list<array{slug: string, title: string, markdown: string, position: int}>} $data */
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        return $this->context->runAs(null, function () use ($data, $publish, $actor): array {
            $result = ['created' => [], 'unchanged' => [], 'skipped' => [], 'pages' => 0];

            foreach ($data['programmes'] as $p) {
                /** @var string $code */
                $code = $p['code'];
                $programme = Programme::query()->updateOrCreate(['code' => $code], [
                    'prefix' => $p['prefix'], 'title' => $p['title'], 'family' => $data['family'], 'position' => $p['position'],
                ]);

                $latest = ProgrammeVersion::query()->where('programme_id', $programme->id)->orderByDesc('number')->first();
                if ($latest !== null && ($latest->source['checksum'] ?? null) === $p['checksum']) {
                    $result['unchanged'][] = $code;

                    continue;
                }
                if ($programme->openDraft()->exists()) {
                    $result['skipped'][] = $code;

                    continue;
                }

                $source = $data['source'] + ['page' => $p['source_page'], 'checksum' => $p['checksum']];
                $version = $this->versions->createDraft($programme, $p, $source, $actor, 'Imported from '.$data['source']['document']);
                if ($publish) {
                    $this->versions->publish($version, $actor);
                }
                $result['created'][] = $code;
            }

            foreach ($data['reference_pages'] as $page) {
                ReferencePage::query()->updateOrCreate(['slug' => $page['slug']], [
                    'title' => $page['title'], 'markdown' => $page['markdown'], 'position' => $page['position'], 'source' => $data['source'],
                ]);
                $result['pages']++;
            }

            $this->audit->record('programmes.imported', null, null, [
                'created' => count($result['created']), 'unchanged' => count($result['unchanged']), 'skipped' => count($result['skipped']), 'published' => $publish,
            ], $actor);

            return $result;
        }, platform: true);
    }
}
