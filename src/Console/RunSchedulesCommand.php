<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Services\ScheduleRunner;
use Illuminate\Console\Command;

class RunSchedulesCommand extends Command
{
    protected $signature = 'ai-page-builder:run-schedules';

    protected $description = 'Run all active UI-defined schedules whose cron expression is due now. Scheduled every minute by the package.';

    public function handle(ScheduleRunner $runner): int
    {
        $results = $runner->runDue();

        if ($results === []) {
            $this->info('[ai-page-builder] No schedules due.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($results as $result) {
            if ($result['status'] === 'ok') {
                $ok++;

                continue;
            }

            $failed++;
            $this->warn(sprintf(
                '[ai-page-builder] Schedule "%s" failed: %s',
                $result['name'],
                $result['error'] ?? 'unknown error'
            ));
        }

        $this->info(sprintf('[ai-page-builder] Ran %d schedule(s): %d ok, %d failed.', count($results), $ok, $failed));

        return self::SUCCESS;
    }
}
