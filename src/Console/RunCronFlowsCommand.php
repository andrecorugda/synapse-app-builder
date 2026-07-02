<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Models\Flow;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class RunCronFlowsCommand extends Command
{
    protected $signature = 'ai-page-builder:run-cron-flows';

    protected $description = 'Run all active cron-triggered flows. Schedule this command at your desired interval. '
        .'(Legacy: prefer Schedules for per-flow cron cadence + function targets; existing trigger_type=cron flows are surfaced as inactive Schedules to review.)';

    public function handle(FlowManager $manager): int
    {
        /** @var class-string<Flow> $model */
        $model = config('ai-page-builder.models.flow', Flow::class);

        /** @var Collection<int, Flow> $flows */
        $flows = $model::query()
            ->active()
            ->where('trigger_type', 'cron')
            ->get();

        $count = $flows->count();

        if ($count === 0) {
            $this->info('[ai-page-builder] No active cron flows found.');

            return self::SUCCESS;
        }

        foreach ($flows as $flow) {
            try {
                $manager->run($flow, []);
            } catch (\Throwable $e) {
                $this->warn(sprintf('[ai-page-builder] Flow "%s" failed: %s', $flow->slug, $e->getMessage()));
            }
        }

        $this->info(sprintf('[ai-page-builder] Ran %d cron flow(s).', $count));

        return self::SUCCESS;
    }
}
