<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities;

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Andre\AiPageBuilder\Blocks\SectionBlock;

/**
 * Holds the editor's draggable blocks (components) — the {@see SectionBlock}
 * vocabulary that feeds the GrapesJS block manager, the AI system prompt, and
 * the HTML sanitizer's expectations.
 *
 * Extensible by design: `PageBuilder::registerComponent(...)` resolves to
 * `register()` here, so host apps and third-party / premium packages add their
 * own blocks without a core change — the same story as {@see NodeRegistry} for
 * nodes and {@see HelperRegistry} for helpers. This unblocks an open-core
 * "premium components" model.
 *
 * Seeded with the built-ins from {@see BlockVocabulary::builtins()} (NOT from
 * the public all(), which now delegates back here — that would recurse). The
 * public BlockVocabulary accessors read through this registry so registered
 * components are visible everywhere a consumer reads the vocabulary.
 *
 * Ordering is stable: built-ins first (registration order), then later
 * registrations. Re-registering an existing key overrides the block in place
 * without changing its position.
 */
class ComponentRegistry
{
    /** @var array<string,SectionBlock> */
    private array $blocks = [];

    /**
     * Register a block. A later registration for an existing key overrides the
     * block while keeping its original position in the ordering.
     */
    public function register(SectionBlock $block): void
    {
        $this->blocks[$block->key] = $block;
    }

    /**
     * Every registered block, built-ins first then later registrations.
     *
     * @return array<int,SectionBlock>
     */
    public function all(): array
    {
        return array_values($this->blocks);
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys($this->blocks);
    }

    public function find(string $key): ?SectionBlock
    {
        return $this->blocks[$key] ?? null;
    }

    /**
     * Serializable form for the GrapesJS block manager (JS side) — the shape
     * consumers expect from {@see BlockVocabulary::toArray()}.
     *
     * @return array<int,array{key:string,label:string,category:string,template:string,description:string,icon:string}>
     */
    public function toArray(): array
    {
        return array_map(static fn (SectionBlock $b): array => $b->toArray(), $this->all());
    }
}
