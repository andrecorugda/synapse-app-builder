<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\FlowContext;

/**
 * Branches the flow on a comparison.
 * config: { left, op: equals|not_equals|contains|gt|lt|empty|not_empty, right }
 * routes to node['next_true'] or node['next_false'].
 */
class ConditionNode implements FlowNodeHandler, ProvidesNodeDefinition
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

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Condition',
            category: CapabilityCategory::FlowControl,
            description: 'Compares two values and branches the flow. The matching branch ("true" or "false") is followed; both sides are interpolated before the comparison.',
            usage: 'left "{{ input.role }}" op "equals" right "admin" → routes down the true branch when the caller is an admin.',
            icon: 'arrows-right-left',
            inputs: [
                new CapabilityInput('left', 'Left value', 'expression', help: 'The value on the left of the comparison. Supports {{ ... }} tokens.'),
                new CapabilityInput('op', 'Operator', 'select', default: 'equals', options: [
                    'equals' => 'equals',
                    'not_equals' => 'not equals',
                    'contains' => 'contains',
                    'gt' => 'greater than',
                    'lt' => 'less than',
                    'empty' => 'is empty',
                    'not_empty' => 'is not empty',
                ]),
                new CapabilityInput('right', 'Right value', 'expression', help: 'The value on the right of the comparison. Ignored by the empty / not_empty operators.', showIf: ['op' => ['equals', 'not_equals', 'contains', 'gt', 'lt']]),
            ],
            outputHandles: ['true', 'false'],
        );
    }
}
