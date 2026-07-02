<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

/**
 * Walks a flow definition graph from its start node, running each node's handler
 * and following the returned next-node ids. Bounded by a max-step cap so a
 * malformed/cyclic graph — or a runaway loop — can't run forever.
 *
 * Sub-sequences: loop / transaction nodes run a BODY sub-graph against the same
 * {@see FlowContext} via {@see runBody()}. The body shares the global step budget
 * (so loops are bounded by the same cap) but gets its own visited set, so the
 * same body nodes can legitimately re-run on every iteration.
 */
class FlowRunner
{
    public function __construct(
        private readonly NodeRegistry $registry,
        private readonly FlowRuntime $runtime,
    ) {}

    /**
     * @param  array<string,mixed>  $definition  { start, nodes: { id => node } }
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $stateOverlay  Per-run overlay for `states.*` (component triggers)
     * @param  ?string  $rootSlug  Slug of the flow being run, seeded onto the call
     *                             stack so a `call_flow` back to THIS flow (a direct
     *                             self-call) is caught at the first level instead of
     *                             running one extra full pass before the guard trips.
     */
    public function run(array $definition, array $input = [], array $stateOverlay = [], ?string $rootSlug = null): FlowContext
    {
        $context = new FlowContext($input, $stateOverlay);

        if (is_string($rootSlug) && $rootSlug !== '') {
            $context->callStack[] = $rootSlug;
        }

        $nodes = $this->nodesOf($definition);
        $start = (string) ($definition['start'] ?? '');
        if ($start === '' || ! isset($nodes[$start])) {
            return $context;
        }

        // Publish the active context so function helpers (ui_notify, ui_redirect,
        // …) invoked deep in the expression sandbox can queue browser actions.
        $this->runtime->setContext($context);
        try {
            $this->walk($nodes, $start, $context, notifyOnFailure: true);
        } finally {
            $this->runtime->setContext(null);
        }

        return $context;
    }

    /**
     * Run a body sub-graph (loop iteration / transaction body) against an EXISTING
     * context — vars and actions accumulate into the parent run. Failure inside a
     * body is left on `$context->failed` for the enclosing node to act on (route a
     * branch, roll back) rather than surfacing a toast directly.
     *
     * @param  array<string,mixed>  $definition  { start, nodes }
     */
    public function runBody(array $definition, FlowContext $context): void
    {
        $nodes = $this->nodesOf($definition);
        $start = (string) ($definition['start'] ?? '');
        if ($start === '' || ! isset($nodes[$start])) {
            return;
        }

        $context->failed = false;
        $context->error = null;
        $this->walk($nodes, $start, $context, notifyOnFailure: false);
    }

    /**
     * @param  array<string,array<string,mixed>>  $nodes
     */
    private function walk(array $nodes, string $start, FlowContext $context, bool $notifyOnFailure): void
    {
        $maxSteps = (int) config('ai-page-builder.flow.max_steps', 1000);
        $queue = [$start];
        $visited = [];

        while ($queue !== [] && $context->stepCount < $maxSteps) {
            $context->stepCount++;
            $id = (string) array_shift($queue);

            // A node may be reached by several branches (fan-in / diamond). Run
            // it at most once per walk so a join doesn't double-fire its handler.
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            $node = $nodes[$id] ?? null;
            if (! is_array($node)) {
                continue;
            }

            $type = (string) ($node['type'] ?? '');
            $handler = $this->registry->get($type);
            if ($handler === null) {
                // An unknown node type is a broken definition, not a benign skip:
                // its `next` never enqueues, so everything downstream is silently
                // dropped. Failing the run (rather than continuing) means a
                // truncated flow is reported as an error, and an enclosing
                // loop/transaction rolls back instead of half-committing.
                $context->steps[] = ['node' => $id, 'type' => $type, 'status' => 'error', 'error' => 'unknown node type'];
                $context->error = "Unknown flow node type '{$type}' on node '{$id}'.";
                $context->failedNode = $id;
                $context->failed = true;
                $this->notifyFailure($context, $notifyOnFailure);

                return;
            }

            // Per-node error handling: retry up to `retry` attempts, then route to
            // an `on_error` node if one is declared, else fail the run gracefully.
            $attempts = max(1, (int) ($node['retry'] ?? ($node['config']['retry'] ?? 1)));
            $lastError = null;
            $ran = false;

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $next = $handler->run($node, $context);
                    $context->steps[] = ['node' => $id, 'type' => $type, 'status' => 'ok', 'attempt' => $attempt];
                    foreach ($next as $nextId) {
                        $queue[] = (string) $nextId;
                    }
                    $ran = true;
                    break;
                } catch (\Throwable $e) {
                    $lastError = $e;
                    $context->steps[] = ['node' => $id, 'type' => $type, 'status' => 'error', 'attempt' => $attempt, 'error' => $e->getMessage()];
                }
            }

            if ($ran) {
                continue;
            }

            $context->error = $lastError?->getMessage();
            $context->failedNode = $id;

            $onError = $node['on_error'] ?? null;
            if (is_string($onError) && $onError !== '' && isset($nodes[$onError])) {
                // Handled: expose the error to the branch and route to it.
                $context->set('error', $context->error);
                $queue[] = $onError;

                continue;
            }

            // Unhandled: mark failed. At top level surface a toast (configurable);
            // inside a body, stay silent so the enclosing node owns the failure.
            $context->failed = true;
            $this->notifyFailure($context, $notifyOnFailure);

            return;
        }

        // Exited the walk with work still queued → the step budget was exhausted
        // mid-flow. This is a FAILURE, never a silent success: otherwise a
        // loop/transaction body that overran the cap would be reported committed
        // while only partly executed (partial writes, wrong `count`). Marking it
        // failed lets an enclosing Transaction roll back and a Loop re-throw.
        if ($queue !== [] && $context->stepCount >= $maxSteps) {
            $context->steps[] = ['node' => '', 'type' => '', 'status' => 'aborted:max-steps'];
            $context->error ??= "Flow exceeded the maximum step budget of {$maxSteps} steps.";
            $context->failed = true;
            $this->notifyFailure($context, $notifyOnFailure);
        }
    }

    /**
     * Queue an error toast for an unhandled top-level failure. Body sub-runs
     * pass $notifyOnFailure = false so the enclosing loop/transaction owns the
     * failure and no toast leaks from a branch that will be handled.
     */
    private function notifyFailure(FlowContext $context, bool $notifyOnFailure): void
    {
        if ($notifyOnFailure && config('ai-page-builder.flow.error_notify', true)) {
            $context->addAction([
                'type' => 'notify',
                'level' => 'error',
                'message' => (string) config('ai-page-builder.flow.error_message', 'Something went wrong. Please try again.'),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,array<string,mixed>>
     */
    private function nodesOf(array $definition): array
    {
        /** @var array<string,array<string,mixed>> $nodes */
        $nodes = (array) ($definition['nodes'] ?? []);

        return $nodes;
    }
}
