<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes\Concerns;

/**
 * Shared body resolution for container nodes (loop / transaction). A body is an
 * inline sub-graph `{ start, nodes }` stored under the node's `config.body`. The
 * canvas wires the node's "body" handle to the first body node; the engine runs
 * that sub-graph against the same context via FlowRunner::runBody().
 */
trait ResolvesFlowBody
{
    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>|null the {start,nodes} sub-definition, or null
     */
    protected function resolveBody(array $config): ?array
    {
        $body = $config['body'] ?? null;
        if (! is_array($body)) {
            return null;
        }

        $start = (string) ($body['start'] ?? '');
        $nodes = $body['nodes'] ?? null;
        if ($start === '' || ! is_array($nodes) || $nodes === []) {
            return null;
        }

        return ['start' => $start, 'nodes' => $nodes];
    }
}
