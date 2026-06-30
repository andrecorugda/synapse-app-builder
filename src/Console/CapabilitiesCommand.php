<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Services\PageBuilderManager;
use Illuminate\Console\Command;

/**
 * Print the merged capability catalogue (every registered flow node + function
 * helper) as JSON. This is the seam an MCP server / AI tool layer consumes: each
 * entry is already tool-shaped — `label` is the tool name, `description` + `usage`
 * are the prose, and `inputs` is the argument schema. See docs/extending-flows.md.
 */
class CapabilitiesCommand extends Command
{
    protected $signature = 'ai-page-builder:capabilities {--pretty : Pretty-print the JSON}';

    protected $description = 'Print the flow-node + helper capability catalogue (MCP/AI tool descriptors) as JSON.';

    public function handle(PageBuilderManager $builder): int
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if ($this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $this->line((string) json_encode($builder->capabilities(), $flags));

        return self::SUCCESS;
    }
}
