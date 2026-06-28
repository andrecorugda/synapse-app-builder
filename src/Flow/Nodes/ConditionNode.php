<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\FlowContext;

/**
 * Branches the flow on a comparison.
 * config: { left, op: equals|not_equals|contains|gt|lt|empty|not_empty, right }
 * routes to node['next_true'] or node['next_false'].
 */
class ConditionNode implements FlowNodeHandler
{
    public function type(): string
    {
        return 'condition';
    }

    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);
        $left = $context->interpolate((string) ($config['left'] ?? ''));
        $right = $context->interpolate((string) ($config['right'] ?? ''));
        $op = (string) ($config['op'] ?? 'equals');

        $result = match ($op) {
            'equals' => $left === $right,
            'not_equals' => $left !== $right,
            'contains' => $right !== '' && str_contains($left, $right),
            'gt' => is_numeric($left) && is_numeric($right) && (float) $left > (float) $right,
            'lt' => is_numeric($left) && is_numeric($right) && (float) $left < (float) $right,
            'empty' => $left === '',
            'not_empty' => $left !== '',
            default => false,
        };

        return (array) ($node[$result ? 'next_true' : 'next_false'] ?? []);
    }
}
