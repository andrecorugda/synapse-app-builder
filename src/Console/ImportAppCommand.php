<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Services\AppImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Import an app from a plan-shaped JSON document (as produced by
 * `ai-page-builder:export-app`). Replays it through the BuildPlanApplier, so
 * the import is idempotent (upsert by key/slug) and reports per-item results.
 */
class ImportAppCommand extends Command
{
    protected $signature = 'ai-page-builder:import-app
        {path : Path to the exported JSON document}';

    protected $description = 'Import a Synapse app from a JSON document (re-applies it via the plan applier).';

    public function handle(AppImporter $importer): int
    {
        $path = (string) $this->argument('path');

        if (! File::exists($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $decoded = json_decode(File::get($path), true);
        if (! is_array($decoded)) {
            $this->error("Could not parse JSON from {$path}.");

            return self::FAILURE;
        }

        /** @var array<string,mixed> $decoded */
        $summary = $importer->import($decoded);

        foreach ($summary['created'] as $section => $items) {
            $this->line(sprintf('  %-12s %d', $section, count($items)));
        }

        if ($summary['errors'] !== []) {
            $this->newLine();
            $this->warn(count($summary['errors']).' issue(s):');
            foreach ($summary['errors'] as $error) {
                $this->line('  - '.$error);
            }
        }

        $this->info('Import complete.');

        return self::SUCCESS;
    }
}
