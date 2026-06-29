<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Services\AppExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Export the whole Synapse app — collections, states, functions, flows, pages
 * and the relevant settings — to ONE plan-shaped JSON document. The result
 * round-trips back in via `ai-page-builder:import-app`.
 *
 * With --path the JSON is written to that file; without it, pretty JSON goes
 * to stdout so it can be piped or redirected.
 */
class ExportAppCommand extends Command
{
    protected $signature = 'ai-page-builder:export-app
        {--path= : Write the JSON to this file (default: print to stdout)}';

    protected $description = 'Export the whole Synapse app (collections, states, functions, flows, pages, settings) to one JSON document.';

    public function handle(AppExporter $exporter): int
    {
        $plan = $exporter->export();
        $json = (string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $path = $this->option('path');
        if (is_string($path) && $path !== '') {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $json);
            $this->info("Exported app to {$path}");

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
