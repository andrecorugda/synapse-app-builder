<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Flow\NodeRegistry;

/*
 * The node drawer (flow-canvas.blade) and the function-helper dropdown
 * (code-field.blade) feed the frontend the registry definitions via @js().
 * These guard the exact payload shape those views iterate over — grouping by
 * category_label/category_order and rendering icon/usage — and that it
 * survives the json_encode @js() performs.
 */

it('serialises node definitions into the drawer payload shape', function (): void {
    $defs = collect(app(NodeRegistry::class)->definitions())
        ->map(fn ($d) => $d->toArray())
        ->values()
        ->all();

    expect($defs)->not->toBeEmpty();

    foreach ($defs as $def) {
        // Keys the drawer template binds to.
        expect($def)->toHaveKeys(['key', 'label', 'icon', 'description', 'category', 'category_label', 'category_order']);
        expect($def['key'])->toBeString()->not->toBe('');
        expect($def['category_order'])->toBeInt();
    }

    // The @js() directive json_encodes the array — must round-trip.
    expect(json_decode(json_encode($defs), true))->toBe($defs);
});

it('serialises helper definitions with a usage snippet for the dropdown', function (): void {
    $defs = collect(app(HelperRegistry::class)->definitions())
        ->map(fn ($d) => $d->toArray())
        ->values()
        ->all();

    expect($defs)->not->toBeEmpty();

    foreach ($defs as $def) {
        expect($def)->toHaveKeys(['key', 'label', 'usage', 'category_label', 'category_order']);
        // insertHelper() inserts def.usage — every helper must carry one.
        expect($def['usage'])->toBeString()->not->toBe('');
    }

    expect(json_decode(json_encode($defs), true))->toBe($defs);
});

it('exposes the ui_alert and ui_modal helpers the runtime actions depend on', function (): void {
    $keys = collect(app(HelperRegistry::class)->definitions())
        ->map(fn ($d) => $d->key)
        ->all();

    expect($keys)->toContain('ui_alert')->toContain('ui_modal');
});
