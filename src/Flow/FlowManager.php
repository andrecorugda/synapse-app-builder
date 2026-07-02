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
     * @param  array<string,mixed>  $stateOverlay  Per-run overlay for `states.*` (page/API triggers)
     * @param  array<string,mixed>  $meta  Provenance for the recorded run (e.g. `watcher_id`) — never reaches the flow
     */
    public function run(Flow $flow, array $input = [], array $stateOverlay = [], array $meta = []): FlowContext
    {
        $startedAt = microtime(true);

        try {
            // $stateOverlay (passed by the page/API endpoint = the live $store.app
            // state) shadows persisted States for `{{ states.* }}` resolution, so a
            // node reads what the visitor typed rather than the empty persisted
            // Variable. Non-page callers (cron/collection/admin Run) pass none.
            $context = $this->runner->run((array) $flow->definition, $input, $stateOverlay);
            // The runner handles node failures in-band (retry / on-error branch /
            // graceful toast) and flags an unhandled failure on the context, so a
            // failed run still returns its actions (e.g. the error notify).
            $this->record($flow, $input, $context, $context->failed ? 'error' : 'ok', $context->error, $startedAt, $meta);

            return $context;
        } catch (\Throwable $e) {
            // Backstop for an unexpected error outside node handling.
            $context = new FlowContext($input);
            $this->record($flow, $input, $context, 'error', $e->getMessage(), $startedAt, $meta);

            throw $e;
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $meta
     */
    private function record(Flow $flow, array $input, FlowContext $context, string $status, ?string $error, float $startedAt, array $meta = []): void
    {
        /** @var class-string<FlowRun> $model */
        $model = config('ai-page-builder.models.flow_run', FlowRun::class);

        $model::create([
            'flow_id' => $flow->id,
            'flow_slug_snapshot' => $flow->slug,
            'watcher_id' => $meta['watcher_id'] ?? null,
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
