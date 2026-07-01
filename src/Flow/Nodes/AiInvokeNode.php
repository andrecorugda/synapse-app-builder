<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\CapabilityInput;
use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;
use Andre\AiPageBuilder\Flow\FlowContext;

/**
 * Calls an AI integration (through the gateway) and stores the text in a var.
 * config: { integration, args:{...}, output:"varName" }
 */
class AiInvokeNode implements FlowNodeHandler, ProvidesNodeDefinition
{
    public function __construct(private readonly AiInvoker $ai) {}

    public function type(): string
    {
        return 'ai_invoke';
    }

    public function definition(): CapabilityDefinition
    {
        return new CapabilityDefinition(
            key: $this->type(),
            label: 'AI Invoke',
            category: CapabilityCategory::Ai,
            description: 'Calls a configured AI integration through the AI gateway and stores the generated text in a context variable. The arguments are interpolated and passed to the integration\'s prompt.',
            usage: 'integration "summarize", args {text: "{{ vars.body }}"}, output "summary" → exposes the generated text as {{ vars.summary }}.',
            icon: 'sparkles',
            inputs: [
                new CapabilityInput('integration', 'Integration', 'string', required: true, help: 'Slug of the AI integration to invoke.'),
                new CapabilityInput('args', 'Arguments', 'keyvalue', help: 'Key/value arguments passed to the AI integration (interpolated). Exposed to the integration\'s prompt variables.'),
                new CapabilityInput('output', 'Output variable', 'string', default: 'ai', help: 'Context variable to receive the generated text (default "ai").'),
            ],
            outputHandles: ['next'],
        );
    }

    public function run(array $node, FlowContext $context): array
    {
        $config = (array) ($node['config'] ?? []);
        $integration = (string) ($config['integration'] ?? '');
        /** @var array<string,mixed> $args */
        $args = $context->interpolateDeep((array) ($config['args'] ?? []));
        $output = (string) ($config['output'] ?? 'ai');

        $context->set($output, $integration !== '' ? $this->ai->invoke($integration, $args) : '');

        return (array) ($node['next'] ?? []);
    }
}
