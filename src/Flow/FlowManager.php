<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowRun;

/**
 * Runs a flow and records a FlowRun telemetry row. Synchronous path — used by
 * component/form triggers that expect result actions back. (Cron/long flows
 * will dispatch this from a queued job in the triggers phase.)
 */
class FlowManager
{
    public function __construct(private readonly FlowRunner $runner) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public function run(Flow $flow, array $input = []): FlowContext
    {
        $startedAt = microtime(true);

        try {
            $context = $this->runner->run((array) $flow->definition, $input);
            $this->record($flow, $input, $context, 'ok', null, $startedAt);

            return $context;
        } catch (\Throwable $e) {
            $context = new FlowContext($input);
            $this->record($flow, $input, $context, 'error', $e->getMessage(), $startedAt);

            throw $e;
        }
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function record(Flow $flow, array $input, FlowContext $context, string $status, ?string $error, float $startedAt): void
    {
        /** @var class-string<FlowRun> $model */
        $model = config('ai-page-builder.models.flow_run', FlowRun::class);

        $model::create([
            'flow_id' => $flow->id,
            'flow_slug_snapshot' => $flow->slug,
            'status' => $status,
            'trigger_type' => $flow->trigger_type,
            'input' => $input,
            'result' => ['actions' => $context->actions, 'vars' => $context->vars],
            'steps' => $context->steps,
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
