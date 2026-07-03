<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowManager;
use Andre\AiPageBuilder\Flow\ResultActionCatalog;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\Partial;

function runModalFlow(array $modalAction, array $input = []): array
{
    $flow = Flow::create([
        'slug' => 'show-modal',
        'name' => 'Show modal',
        'trigger_type' => 'manual',
        'is_active' => true,
        'definition' => [
            'start' => 't',
            'nodes' => [
                't' => ['type' => 'trigger', 'next' => ['r']],
                'r' => ['type' => 'result', 'config' => ['actions' => [['type' => 'modal'] + $modalAction]]],
            ],
        ],
    ]);

    return app(FlowManager::class)->run($flow, $input)->actions;
}

it('resolves a partial into the modal action html and interpolates it', function (): void {
    Partial::create([
        'slug' => 'promo-box',
        'name' => 'Promo box',
        'html' => '<div class="promo"><h3>Hi {{ input.name }}</h3><p>Welcome aboard.</p></div>',
    ]);

    $actions = runModalFlow(
        ['target' => '#promo', 'action' => 'open', 'partial' => 'promo-box'],
        ['name' => 'Sam'],
    );

    expect($actions)->toHaveCount(1)
        ->and($actions[0]['type'])->toBe('modal')
        ->and($actions[0]['target'])->toBe('#promo')
        ->and($actions[0]['html'])->toContain('Welcome aboard')
        ->and($actions[0]['html'])->toContain('Hi Sam')   // partial's own tokens interpolated
        ->and($actions[0])->not->toHaveKey('partial');     // resolved away, runtime only sees html
});

it('lets an explicit html win over the partial', function (): void {
    Partial::create(['slug' => 'promo-box', 'name' => 'Promo', 'html' => '<p>partial</p>']);

    $actions = runModalFlow(['target' => '#m', 'action' => 'open', 'partial' => 'promo-box', 'html' => '<p>explicit</p>']);

    expect($actions[0]['html'])->toBe('<p>explicit</p>');
});

it('a close action carries no html/partial', function (): void {
    $actions = runModalFlow(['target' => '#m', 'action' => 'close']);

    expect($actions[0])->toMatchArray(['type' => 'modal', 'action' => 'close'])
        ->and($actions[0])->not->toHaveKey('partial');
});

it('the modal action catalog offers a partial picker with existing partials', function (): void {
    Partial::create(['slug' => 'promo-box', 'name' => 'Promo box', 'html' => '<p>x</p>']);

    $modal = ResultActionCatalog::types()['modal'];
    $partialField = collect($modal['fields'])->firstWhere('key', 'partial');

    expect($partialField)->not->toBeNull()
        ->and($partialField['type'])->toBe('select')
        ->and($partialField['options'])->toHaveKey('promo-box')
        ->and($partialField['options']['promo-box'])->toBe('Promo box');
});
