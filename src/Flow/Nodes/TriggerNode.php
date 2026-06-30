<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\FlowContext;

/** Entry node — does nothing but hand off to its next node(s). */
class TriggerNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    public function type(): string
    {
        return 'trigger';
    }

    public function run(array $node, FlowContext $context): array
    {
        return (array) ($node['next'] ?? []);
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'Trigger',
            category: CapabilityCategory::FlowControl,
            description: 'The starting point of a flow. Every flow begins here; it takes no input and simply passes control to the node(s) connected to its output.',
            usage: 'Drop one Trigger at the top of the flow and wire it to the first step.',
            icon: 'play',
            inputs: [],
            outputHandles: ['next'],
        );
    }
}
