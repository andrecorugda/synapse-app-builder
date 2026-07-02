<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Blocks\SectionBlock;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\ComponentRegistry;
use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Flow\Contracts\FlowNodeHandler;
use Andre\AiPageBuilder\Flow\NodeRegistry;
use Andre\AiPageBuilder\Models\Page;

/**
 * Programmatic entry point behind the PageBuilder facade. Exposes page rendering
 * plus the public extensibility seam: host apps and third-party packages register
 * their own flow nodes / function helpers here (from a service provider's boot()),
 * and read back the merged capability catalogue that feeds the builder UI and the
 * MCP/AI tool listing.
 */
class PageBuilderManager
{
    public function __construct(
        private readonly PageRenderer $renderer,
        private readonly NodeRegistry $nodes,
        private readonly HelperRegistry $helpers,
        private readonly ComponentRegistry $components,
    ) {}

    /** Fully-rendered (cached) HTML for a published page. */
    public function render(Page $page): string
    {
        return $this->renderer->renderCached($page);
    }

    /** Bust the render cache for a slug. */
    public function forget(string $slug): void
    {
        $this->renderer->forget($slug);
    }

    /**
     * Register a custom flow node. Call from a service provider's boot(); the node
     * becomes resolvable by the flow runner, valid in the BuildPlan validator, and
     * (if it implements ProvidesNodeDefinition) appears in the canvas drawer and the
     * capabilities() catalogue — no core change.
     */
    public function registerNode(FlowNodeHandler $handler): void
    {
        $this->nodes->register($handler);
    }

    /**
     * Register a custom function helper — a CapabilityDefinition (kind 'helper')
     * paired with the PHP callable it invokes. The helper becomes callable inside
     * the expression sandbox by its key and appears in the function-editor dropdown
     * and the capabilities() catalogue.
     */
    public function registerHelper(CapabilityDefinition $definition, callable $fn): void
    {
        $this->helpers->register($definition, $fn);
    }

    /**
     * Register a custom draggable block (component) — a {@see SectionBlock}.
     * Call from a service provider's boot(); the block appears in the GrapesJS
     * block manager, the AI system prompt vocabulary, and the capabilities()
     * catalogue (kind 'component') — no core change. A later registration for
     * an existing key overrides it in place. This is the seam premium/third-
     * party component packages register through.
     */
    public function registerComponent(SectionBlock $block): void
    {
        $this->components->register($block);
    }

    /**
     * Every registered block (built-in + third-party), serialized for the
     * GrapesJS block manager — the same shape as BlockVocabulary::toArray().
     *
     * @return array<int,array<string,mixed>>
     */
    public function components(): array
    {
        return $this->components->toArray();
    }

    /**
     * The merged capability catalogue — every registered flow node, function
     * helper, and draggable block (component). Nodes/helpers are serialized via
     * {@see CapabilityDefinition::toArray()}; components are mapped to a
     * catalogue entry {key,label,kind:'component',category,description,icon}.
     * This is the single MCP/AI-facing list an agent reads to know what it can
     * place and call.
     *
     * @return array<int,array<string,mixed>>
     */
    public function capabilities(): array
    {
        $definitions = array_merge(
            $this->nodes->definitions(),
            $this->helpers->definitions(),
        );

        $catalogue = array_map(
            static fn (CapabilityDefinition $d): array => $d->toArray(),
            $definitions,
        );

        foreach ($this->components->all() as $block) {
            $catalogue[] = [
                'key' => $block->key,
                'label' => $block->label,
                'kind' => 'component',
                'category' => $block->category,
                'description' => $block->description,
                'icon' => $block->icon,
            ];
        }

        return $catalogue;
    }
}
