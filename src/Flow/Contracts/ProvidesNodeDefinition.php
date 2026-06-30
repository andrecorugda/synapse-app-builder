<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Contracts;

use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Flow\NodeRegistry;

/**
 * Optional companion to {@see FlowNodeHandler}: a node implements this to declare
 * its drawer/MCP metadata (label, category, description, usage, input schema).
 *
 * It is deliberately separate from FlowNodeHandler so existing and third-party
 * handlers keep working without change — the {@see NodeRegistry}
 * synthesizes a minimal definition for any handler that does not implement this.
 */
interface ProvidesNodeDefinition
{
    public function definition(): CapabilityDefinition;
}
