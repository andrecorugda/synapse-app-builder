<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\FlowContext;

/**
 * Calls an AI integration (through the gateway) and stores the text in a var.
 * config: { integration, args:{...}, output:"varName" }
 */
class AiInvokeNode implements FlowNodeHandler
{
    public function __construct(private readonly AiInvoker $ai) {}

    public function type(): string
    {
        return 'ai_invoke';
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
