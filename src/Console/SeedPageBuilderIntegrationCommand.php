<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Seeders\PageBuilderIntegrationSeeder;
use Illuminate\Console\Command;

class SeedPageBuilderIntegrationCommand extends Command
{
    protected $signature = 'ai-page-builder:seed-integration';

    protected $description = 'Seed the pre-configured "page_builder" integration into the AI OpenRouter Gateway (if installed).';

    public function handle(PageBuilderIntegrationSeeder $seeder): int
    {
        if (! PageBuilderIntegrationSeeder::gatewayInstalled()) {
            $this->warn('AI OpenRouter Gateway is not installed — nothing to seed. Install andrecorugda/ai-openrouter-gateway for the optimized AI path.');

            return self::SUCCESS;
        }

        $created = $seeder->run();

        $this->info($created
            ? 'Created the "page_builder" gateway integration.'
            : 'The "page_builder" integration already exists (left untouched).');

        return self::SUCCESS;
    }
}
