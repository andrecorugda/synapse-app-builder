<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Schedule;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Evaluates UI-defined {@see Schedule} rows and runs the ones whose cron
 * expression is due. Scheduled every minute by the service provider via the
 * `ai-page-builder:run-schedules` command, so "due now" is resolved per minute.
 *
 * A flow target is run through {@see FlowManager} (telemetry + result actions);
 * a function target is run through {@see FlowRunner} using a synthetic one-node
 * `function` graph, which reuses the existing FunctionNode handler — the engine
 * is only ever called through its public methods, never modified.
 *
 * Each run is isolated: a failure stamps the row's `last_status`/`last_error`
 * and the loop continues, so one bad schedule never aborts the rest.
 */
class ScheduleRunner
{
    public function __construct(
        private readonly FlowManager $flows,
        private readonly FlowRunner $runner,
    ) {}

    /**
     * Run every active schedule that is due at $now (default: now()).
     *
     * @return array<int,array{id:int,name:string,status:string,error:?string}>
     *                                                                          A per-schedule summary of what ran this tick.
     */
    public function runDue(?CarbonInterface $now = null): array
    {
        $now = $now ?? Carbon::now();

        /** @var class-string<Schedule> $model */
        $model = config('ai-page-builder.models.schedule', Schedule::class);

        $summary = [];

        foreach ($model::query()->active()->get() as $schedule) {
            if (! $this->isDue($schedule, $now)) {
                continue;
            }

            $summary[] = $this->runOne($schedule, $now);
        }

        return $summary;
    }

    /**
     * Is this schedule's cron expression due at $now, evaluated in the
     * schedule's own timezone when one is set? A malformed expression is
     * treated as not-due (and logged) rather than throwing.
     */
    private function isDue(Schedule $schedule, CarbonInterface $now): bool
    {
        $tz = $schedule->timezone;
        $reference = $tz !== null && $tz !== '' ? $now->copy()->setTimezone($tz) : $now;

        try {
            return (new CronExpression($schedule->cron_expression))->isDue($reference);
        } catch (\Throwable $e) {
            Log::warning('[ai-page-builder] invalid cron expression on schedule', [
                'schedule' => $schedule->id,
                'expression' => $schedule->cron_expression,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Dispatch a single due schedule and stamp its run telemetry.
     *
     * @return array{id:int,name:string,status:string,error:?string}
     */
    private function runOne(Schedule $schedule, CarbonInterface $now): array
    {
        $status = 'ok';
        $error = null;

        try {
            match ($schedule->target_type) {
                'flow' => $this->runFlow($schedule),
                'function' => $this->runFunction($schedule),
                default => throw new \RuntimeException(
                    sprintf('Unknown schedule target_type "%s".', $schedule->target_type)
                ),
            };
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();

            Log::warning('[ai-page-builder] scheduled run failed', [
                'schedule' => $schedule->id,
                'target_type' => $schedule->target_type,
                'target_key' => $schedule->target_key,
                'error' => $error,
            ]);
        }

        $schedule->forceFill([
            'last_run_at' => $now,
            'last_status' => $status,
            'last_error' => $error,
        ])->save();

        return [
            'id' => (int) $schedule->id,
            'name' => (string) $schedule->name,
            'status' => $status,
            'error' => $error,
        ];
    }

    /**
     * Resolve the target Flow by slug and run it with the schedule's args as
     * input.
     */
    private function runFlow(Schedule $schedule): void
    {
        /** @var class-string<Flow> $model */
        $model = config('ai-page-builder.models.flow', Flow::class);

        /** @var Flow|null $flow */
        $flow = $model::query()->where('slug', $schedule->target_key)->first();

        if ($flow === null) {
            throw new \RuntimeException(sprintf('Flow "%s" not found.', $schedule->target_key));
        }

        $this->flows->run($flow, $this->args($schedule));
    }

    /**
     * Run the target Function by slug. There is no standalone function runner —
     * function execution lives in the FunctionNode handler — so we drive it
     * through the public FlowRunner with a synthetic one-node `function` graph.
     * This reuses the engine exactly as a real flow would, without touching it.
     */
    private function runFunction(Schedule $schedule): void
    {
        /** @var class-string<FlowFunction> $model */
        $model = config('ai-page-builder.models.flow_function', FlowFunction::class);

        if ($model::query()->where('slug', $schedule->target_key)->doesntExist()) {
            throw new \RuntimeException(sprintf('Function "%s" not found.', $schedule->target_key));
        }

        $this->runner->run([
            'start' => 'call',
            'nodes' => [
                'call' => [
                    'type' => 'function',
                    'config' => [
                        'function' => $schedule->target_key,
                        'args' => $this->args($schedule),
                        'output' => 'result',
                    ],
                ],
            ],
        ], $this->args($schedule));
    }

    /**
     * @return array<string,mixed>
     */
    private function args(Schedule $schedule): array
    {
        return (array) ($schedule->args ?? []);
    }
}
