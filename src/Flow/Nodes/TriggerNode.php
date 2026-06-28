<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Nodes;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\FlowContext;

/** Entry node — does nothing but hand off to its next node(s). */
class TriggerNode implements FlowNodeHandler
{
    public function type(): string
    {
        return 'trigger';
    }

    public function run(array $node, FlowContext $context): array
    {
        return (array) ($node['next'] ?? []);
    }
}
