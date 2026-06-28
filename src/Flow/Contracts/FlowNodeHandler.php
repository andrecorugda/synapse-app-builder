<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Contracts;

use Andre\AiPageBuilder\Flow\FlowContext;

/**
 * Handles one node type. run() mutates the context (sets vars / appends actions)
 * and returns the ids of the next node(s) to execute.
 */
interface FlowNodeHandler
{
    public function type(): string;

    /**
     * @param  array<string,mixed>  $node  The node definition ({type,config,next,...}).
     * @return array<int,string> next node ids
     */
    public function run(array $node, FlowContext $context): array;
}
