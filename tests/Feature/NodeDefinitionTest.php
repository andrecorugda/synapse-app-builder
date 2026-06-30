<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Capabilities\CapabilityDefinition;
use Andre\AiPageBuilder\Flow\NodeRegistry;

it('yields a definition for every registered node type', function (): void {
    $registry = app(NodeRegistry::class);
    $defs = collect($registry->definitions())->keyBy(fn (CapabilityDefinition $d): string => $d->key);

    foreach ($registry->types() as $type) {
        expect($defs->has($type))->toBeTrue("missing definition for node type [{$type}]");
    }
});

it('returns one definition per registered type', function (): void {
    $registry = app(NodeRegistry::class);

    expect($registry->definitions())->toHaveCount(count($registry->types()));
});

it('gives every definition a non-empty label and a key matching the node type', function (): void {
    $registry = app(NodeRegistry::class);
    $types = $registry->types();

    foreach ($registry->definitions() as $def) {
        expect($def->label)->not->toBe('');
        expect($def->key)->toBeIn($types);
    }
});

it('routes the condition node through true and false handles', function (): void {
    $registry = app(NodeRegistry::class);

    $condition = collect($registry->definitions())
        ->firstWhere(fn (CapabilityDefinition $d): bool => $d->key === 'condition');

    expect($condition)->not->toBeNull();
    expect($condition->outputHandles)->toBe(['true', 'false']);
});
