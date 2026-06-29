<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

/**
 * Walks a flow definition graph from its start node, running each node's handler
 * and following the returned next-node ids. Bounded by a max-step cap so a
 * malformed/cyclic graph can't loop forever.
 */
class FlowRunner
{
    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * @param  array<string,mixed>  $definition  { start, nodes: { id => node } }
     * @param  array<string,mixed>  $input
     */
    public function run(array $definition, array $input = []): FlowContext
    {
        $context = new FlowContext($input);

        /** @var array<string,array<string,mixed>> $nodes */
        $nodes = (array) ($definition['nodes'] ?? []);
        $start = (string) ($definition['start'] ?? '');

        if ($start === '' || ! isset($nodes[$start])) {
            return $context;
        }

        $maxSteps = (int) config('ai-page-builder.flow.max_steps', 200);
        $queue = [$start];
        $visited = [];
        $steps = 0;

        while ($queue !== [] && $steps < $maxSteps) {
            $steps++;
            $id = array_shift($queue);

            // A node may be reached by several branches (fan-in / diamond). Run
            // it at most once per flow so a join doesn't double-fire its handler.
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
                $context->steps[] = ['node' => $id, 'type' => $type, 'status' => 'skipped:unknown-type'];

                continue;
            }

            // Per-node error handling: retry up to `retry` attempts, then route to
            // an `on_error` node if one is declared, else fail the run gracefully
            // (a toast is surfaced to the page rather than a dead 500).
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

            // Unhandled: mark failed, surface a toast (configurable), and stop.
            $context->failed = true;
            if (config('ai-page-builder.flow.error_notify', true)) {
                $context->addAction([
                    'type' => 'notify',
                    'level' => 'error',
                    'message' => (string) config('ai-page-builder.flow.error_message', 'Something went wrong. Please try again.'),
                ]);
            }
            break;
        }

        return $context;
    }
}
