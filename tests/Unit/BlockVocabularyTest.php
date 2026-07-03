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

it('data/interactive components expose their config + carry the bug fixes', function (): void {
    $keys = fn (SectionBlock $b) => array_map(fn ($s) => $s->key, $b->settings);

    // Autocomplete: hidden value input now has a name so the picked id submits.
    $ac = BlockVocabulary::find('autocomplete');
    expect($ac->template)->toContain('class="pb-autocomplete__value" name="autocomplete_id"')
        ->and($keys($ac))->toContain('data-pb-value-name');

    // List: display-field is configurable.
    expect($keys(BlockVocabulary::find('list')))->toContain('data-pb-item-field');

    // Editable grid: template binds to the CONFIGURED keys, not hardcoded qty/price.
    $grid = BlockVocabulary::find('editable_grid');
    expect($grid->template)->toContain('x-model.number="row[qtyKey]"')
        ->and($grid->template)->toContain('x-model.number="row[priceKey]"')
        ->and($grid->template)->not->toContain('x-model.number="row.qty"');
});

it('media components expose value/appearance settings', function (): void {
    $keys = fn (SectionBlock $b) => array_map(fn ($s) => $s->key, $b->settings);

    expect($keys(BlockVocabulary::find('rating')))->toContain('data-pb-value', 'data-pb-max');
    expect($keys(BlockVocabulary::find('progress')))->toContain('data-pb-percent', 'data-pb-variant');
    expect($keys(BlockVocabulary::find('alert')))->toContain('data-pb-severity');
    expect($keys(BlockVocabulary::find('video')))->toContain('data-pb-video-src', 'data-pb-controls');
    expect($keys(BlockVocabulary::find('avatar')))->toContain('src', 'data-pb-size', 'data-pb-shape');
});

it('modal ships a hidden close-icon button the config CSS can reveal', function (): void {
    // The ✕ carries data-pb-close so the existing runtime closes it; it's hidden
    // until data-pb-close-icon="true" (config CSS).
    expect(BlockVocabulary::find('modal')->template)->toContain('pb-modal__x');
});
