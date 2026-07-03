<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Andre\AiPageBuilder\Blocks\SectionBlock;

it('exposes a rich set of section keys for the AI vocabulary', function (): void {
    $keys = BlockVocabulary::keys();

    expect($keys)->toContain('hero', 'features', 'pricing', 'testimonial', 'cta', 'footer')
        ->and(count($keys))->toBeGreaterThanOrEqual(10);
});

it('wraps every section template in a matching data-pb-block', function (): void {
    foreach (BlockVocabulary::sections() as $block) {
        expect($block->template)->toContain('data-pb-block="'.$block->key.'"');
    }
});

it('provides primitive basics that are not data-pb-block sections', function (): void {
    $basics = BlockVocabulary::basics();

    expect(count($basics))->toBeGreaterThanOrEqual(5);
    foreach ($basics as $block) {
        expect($block->category)->toBe('Basic')
            ->and($block->template)->not->toContain('data-pb-block');
    }
});

it('finds a block by key and returns null for unknown', function (): void {
    expect(BlockVocabulary::find('hero'))->not->toBeNull()
        ->and(BlockVocabulary::find('navbar'))->not->toBeNull()
        ->and(BlockVocabulary::find('nope'))->toBeNull();
});

it('serializes all blocks for the JS block manager', function (): void {
    $arr = BlockVocabulary::toArray();
    expect($arr)->toHaveCount(count(BlockVocabulary::all()))
        ->and($arr[0])->toHaveKeys(['key', 'label', 'category', 'template', 'description']);
});

it('declares author-configurable settings on the overlay/disclosure components', function (): void {
    $keys = fn (SectionBlock $b) => array_map(fn ($s) => $s->key, $b->settings);

    $modal = BlockVocabulary::find('modal');
    expect($keys($modal))->toContain('data-pb-display', 'data-pb-size', 'data-pb-backdrop-close', 'data-pb-close-icon');

    $drawer = BlockVocabulary::find('drawer');
    expect($keys($drawer))->toContain('data-pb-side', 'data-pb-size', 'data-pb-backdrop-close');

    expect($keys(BlockVocabulary::find('tabs')))->toContain('data-pb-default-tab');
    expect($keys(BlockVocabulary::find('accordion')))->toContain('data-pb-single-open');
    expect($keys(BlockVocabulary::find('tooltip')))->toContain('data-pb-side');
    expect($keys(BlockVocabulary::find('banner')))->toContain('data-pb-variant', 'data-pb-dismissible');
    expect($keys(BlockVocabulary::find('context_menu')))->toContain('data-pb-trigger');

    // Each setting round-trips through toArray with a default the editor can seed.
    $modalArr = collect(BlockVocabulary::toArray())->firstWhere('key', 'modal');
    expect($modalArr['settings'][0])->toHaveKeys(['key', 'label', 'type', 'options', 'category', 'default']);
});

it('modal ships a hidden close-icon button the config CSS can reveal', function (): void {
    // The ✕ carries data-pb-close so the existing runtime closes it; it's hidden
    // until data-pb-close-icon="true" (config CSS).
    expect(BlockVocabulary::find('modal')->template)->toContain('pb-modal__x');
});
