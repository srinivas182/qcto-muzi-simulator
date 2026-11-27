<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Programmes\GuideImporter;
use Illuminate\Console\Command;

final class ProgrammesImport extends Command
{
    protected $signature = 'programmes:import {--file= : Path to a guide JSON file} {--publish : Publish the new versions straight away (first load only)}';

    protected $description = 'Load the Workplace Practical Expectations programmes (13) and reference pages into the catalogue';

    public function handle(GuideImporter $importer): int
    {
        $result = $importer->import($this->option('file') ? (string) $this->option('file') : null, (bool) $this->option('publish'));

        $this->info(sprintf('New versions: %d · unchanged: %d · skipped (draft open): %d · reference pages: %d',
            count($result['created']), count($result['unchanged']), count($result['skipped']), $result['pages']));
        foreach ($result['skipped'] as $code) {
            $this->warn("Skipped {$code}: a draft is being edited in Programme Studio.");
        }

        return self::SUCCESS;
    }
}
