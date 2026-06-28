<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Blocks\BlockVocabulary;

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
