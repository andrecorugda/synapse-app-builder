<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Capabilities\CapabilityCategory;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\Contracts\ProvidesNodeDefinition;

/**
 * Maps node `type` => handler, and exposes each node's drawer/MCP metadata.
 *
 * Extensible by design: host apps and third-party packages register their own
 * handlers (`PageBuilder::registerNode(...)` boils down to `register()` here).
 * A handler that implements {@see ProvidesNodeDefinition} contributes rich
 * metadata to the node drawer; one that does not still works — `definitions()`
 * synthesizes a minimal entry so every registered node is at least discoverable.
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

    /**
     * Every registered node's definition, sorted by category order then label —
     * the exact shape the canvas drawer and the MCP tool catalogue consume.
     *
     * @return array<int,CapabilityDefinition>
     */
    public function definitions(): array
    {
        $defs = [];
        foreach ($this->handlers as $type => $handler) {
            $defs[] = $handler instanceof ProvidesNodeDefinition
                ? $handler->definition()
                : $this->fallbackDefinition($type);
        }

        usort($defs, static function (CapabilityDefinition $a, CapabilityDefinition $b): int {
            return [$a->category->order(), $a->label] <=> [$b->category->order(), $b->label];
        });

        return $defs;
    }

    /**
     * A minimal definition for a handler that does not describe itself — keeps an
     * undocumented (e.g. third-party) node discoverable rather than invisible.
     */
    private function fallbackDefinition(string $type): CapabilityDefinition
    {
        $label = ucwords(str_replace(['_', '-', '.'], ' ', $type));

        return new CapabilityDefinition(
            key: $type,
            label: $label,
            category: CapabilityCategory::Util,
            description: 'Custom node.',
        );
    }
}
