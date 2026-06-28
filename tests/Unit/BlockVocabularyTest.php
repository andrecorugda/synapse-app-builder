<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Blocks\BlockVocabulary;

it('defines the six landing-page sections', function (): void {
    expect(BlockVocabulary::keys())->toEqual(['hero', 'features', 'pricing', 'testimonial', 'cta', 'footer']);
});

it('wraps every template in a matching data-pb-block section', function (): void {
    foreach (BlockVocabulary::all() as $block) {
        expect($block->template)->toContain('data-pb-block="'.$block->key.'"');
    }
});

it('finds a block by key and returns null for unknown', function (): void {
    expect(BlockVocabulary::find('hero'))->not->toBeNull()
        ->and(BlockVocabulary::find('nope'))->toBeNull();
});

it('serializes to arrays for the JS block manager', function (): void {
    $arr = BlockVocabulary::toArray();
    expect($arr)->toHaveCount(6)
        ->and($arr[0])->toHaveKeys(['key', 'label', 'category', 'template', 'description']);
});
