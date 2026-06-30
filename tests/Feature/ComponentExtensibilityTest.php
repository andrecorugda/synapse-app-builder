<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Andre\AiPageBuilder\Blocks\SectionBlock;
use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Capabilities\ComponentRegistry;
use Andre\AiPageBuilder\Facades\PageBuilder;
use Andre\AiPageBuilder\Filament\Forms\Components\GrapesJsField;

/**
 * Component extensibility — the mirror of the flow node/helper seam. A host app
 * / third-party / premium package registers its own draggable block via the
 * PageBuilder facade and gets it in the editor vocabulary, the BlockVocabulary
 * accessors, and the MCP/AI capability catalogue — with no core change.
 */
it('keeps built-in blocks resolvable through the BlockVocabulary accessors', function (): void {
    expect(BlockVocabulary::keys())->toContain('hero');
    expect(collect(BlockVocabulary::all())->pluck('key'))->toContain('hero');

    $hero = BlockVocabulary::find('hero');
    expect($hero)->toBeInstanceOf(SectionBlock::class)
        ->and($hero->key)->toBe('hero');

    expect(collect(BlockVocabulary::toArray())->pluck('key'))->toContain('hero');
});

it('registers a custom component via the facade and surfaces it everywhere consumers read', function (): void {
    PageBuilder::registerComponent(new SectionBlock(
        'pro_pricing',
        'Pro Pricing',
        'Pro',
        '<section data-pb-block="pro_pricing">...</section>',
        'desc',
        'star',
    ));

    // The full block list (all/find/toArray) includes it; keys() is SECTION-scoped
    // (the AI page-generation vocabulary), so a non-"Sections" component is
    // intentionally NOT surfaced there — it's a drag-only block.
    expect(collect(BlockVocabulary::all())->pluck('key'))->toContain('pro_pricing');
    expect(BlockVocabulary::keys())->not->toContain('pro_pricing');

    $block = BlockVocabulary::find('pro_pricing');
    expect($block)->toBeInstanceOf(SectionBlock::class)
        ->and($block->label)->toBe('Pro Pricing')
        ->and($block->category)->toBe('Pro')
        ->and($block->icon)->toBe('star');

    // The registry's own toArray() and the GrapesJsField that consumes it.
    expect(collect(app(ComponentRegistry::class)->toArray())->pluck('key'))->toContain('pro_pricing');
    expect(collect((new GrapesJsField('content'))->getBlocks())->pluck('key'))->toContain('pro_pricing');

    // Built-ins still present (no regression / no clobber).
    expect(BlockVocabulary::keys())->toContain('hero');
});

it('adds a registered "Sections"-category component to the AI page vocabulary (keys)', function (): void {
    PageBuilder::registerComponent(new SectionBlock(
        'pro_hero',
        'Pro Hero',
        BlockVocabulary::SECTION_CATEGORY,
        '<section data-pb-block="pro_hero">...</section>',
        'A premium hero section.',
        'star',
    ));

    expect(BlockVocabulary::keys())->toContain('pro_hero')   // joins the AI section vocab
        ->and(BlockVocabulary::keys())->toContain('hero');   // built-in section still present
});

it('overrides an existing key on re-registration, keeping its position stable', function (): void {
    $before = array_search('hero', BlockVocabulary::keys(), true);

    PageBuilder::registerComponent(new SectionBlock(
        'hero',
        'Custom Hero',
        'Sections',
        '<section data-pb-block="hero">override</section>',
        'overridden',
        'star',
    ));

    expect(BlockVocabulary::find('hero')->label)->toBe('Custom Hero');
    expect(array_search('hero', BlockVocabulary::keys(), true))->toBe($before);
});

it('exposes the registered component in the capability catalogue alongside nodes and helpers', function (): void {
    PageBuilder::registerComponent(new SectionBlock(
        'pro_pricing',
        'Pro Pricing',
        'Pro',
        '<section data-pb-block="pro_pricing">...</section>',
        'desc',
        'star',
    ));

    $entry = collect(PageBuilder::capabilities())->firstWhere('key', 'pro_pricing');
    expect($entry)->not->toBeNull()
        ->and($entry['kind'])->toBe('component')
        ->and($entry['label'])->toBe('Pro Pricing')
        ->and($entry['category'])->toBe('Pro');

    $kinds = collect(PageBuilder::capabilities())->pluck('kind')->unique()->values()->all();
    expect($kinds)->toContain('component')
        ->and($kinds)->toContain(CapabilityDefinition::KIND_NODE)
        ->and($kinds)->toContain(CapabilityDefinition::KIND_HELPER);
});

it('returns the full block catalogue from PageBuilder::components()', function (): void {
    $keys = collect(PageBuilder::components())->pluck('key');

    expect($keys)->toContain('hero')
        ->and($keys)->toContain('card')
        ->and($keys)->toContain('data_table');
});
