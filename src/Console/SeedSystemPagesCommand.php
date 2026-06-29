<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Seeders\SystemPagesSeeder;
use Illuminate\Console\Command;

class SeedSystemPagesCommand extends Command
{
    protected $signature = 'ai-page-builder:seed-system-pages';

    protected $description = 'Seed the built-in 404 (not-found) and maintenance pages as editable Synapse pages (idempotent).';

    public function handle(SystemPagesSeeder $seeder): int
    {
        $seeder->run();

        $this->info('System pages ensured: not-found, maintenance.');

        return self::SUCCESS;
    }
}
