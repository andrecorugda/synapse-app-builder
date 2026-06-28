<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;

/**
 * Maps node `type` => handler. Extensible: host apps / later phases can register
 * custom node types.
 */
class NodeRegistry
{
    /** @var array<string,FlowNodeHandler> */
    private array $handlers = [];

    public function register(FlowNodeHandler $handler): void
    {
        $this->handlers[$handler->type()] = $handler;
    }

    public function get(string $type): ?FlowNodeHandler
    {
        return $this->handlers[$type] ?? null;
    }

    /** @return array<int,string> */
    public function types(): array
    {
        return array_keys($this->handlers);
    }
}
