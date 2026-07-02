<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\ExpressionEvaluator;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\Nodes\FunctionNode;
use Andre\AiPageBuilder\Flow\Nodes\SetVariableNode;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Services\Data\VariableStore;

// ---------------------------------------------------------------------------
// Store: set + get with each type
// ---------------------------------------------------------------------------

it('stores and reads a string value', function (): void {
    $store = app(VariableStore::class);
    $store->set('greeting', 'hello', 'string');

    expect($store->get('greeting'))->toBe('hello');
});

it('reads a number value back as int or float', function (): void {
    $store = app(VariableStore::class);
    $store->set('count', 42, 'number');
    $store->set('rate', 0.2, 'number');

    expect($store->get('count'))->toBe(42)
        ->and($store->get('rate'))->toBe(0.2);
});

it('reads a boolean value back as bool', function (): void {
    $store = app(VariableStore::class);
    $store->set('enabled', true, 'boolean');
    $store->set('disabled', false, 'boolean');

    expect($store->get('enabled'))->toBeTrue()
        ->and($store->get('disabled'))->toBeFalse();
});

it('reads a json value back as an array', function (): void {
    $store = app(VariableStore::class);
    $store->set('config', ['a' => 1, 'b' => [2, 3]], 'json');

    expect($store->get('config'))->toBe(['a' => 1, 'b' => [2, 3]]);
});

it('returns the default for an unknown key', function (): void {
    $store = app(VariableStore::class);

    expect($store->get('nope', 'fallback'))->toBe('fallback');
});

// ---------------------------------------------------------------------------
// Store: all(), has(), forget(), type inference
// ---------------------------------------------------------------------------

it('returns the full map of typed values from all()', function (): void {
    $store = app(VariableStore::class);
    $store->set('a', 1, 'number');
    $store->set('b', 'two', 'string');

    expect($store->all())->toBe(['a' => 1, 'b' => 'two']);
});

it('reports presence with has() and removes with forget()', function (): void {
    $store = app(VariableStore::class);
    $store->set('temp', 'x');

    expect($store->has('temp'))->toBeTrue();

    $store->forget('temp');

    expect($store->has('temp'))->toBeFalse()
        ->and($store->get('temp'))->toBeNull();
});

it('infers the type from the PHP value when none is given', function (): void {
    $store = app(VariableStore::class);
    $store->set('n', 7);
    $store->set('f', 1.5);
    $store->set('b', true);
    $store->set('j', ['x' => 1]);
    $store->set('s', 'text');

    expect($store->get('n'))->toBe(7)
        ->and($store->get('f'))->toBe(1.5)
        ->and($store->get('b'))->toBeTrue()
        ->and($store->get('j'))->toBe(['x' => 1])
        ->and($store->get('s'))->toBe('text');
});

it('upserts an existing key rather than duplicating it', function (): void {
    $store = app(VariableStore::class);
    $store->set('k', 'first', 'string');
    $store->set('k', 'second', 'string');

    expect($store->get('k'))->toBe('second')
        ->and(array_keys($store->all()))->toBe(['k']);
});

// ---------------------------------------------------------------------------
// Flow integration: FlowContext globals + expression global()
// ---------------------------------------------------------------------------

it('interpolates {{ globals.x }} in a FlowContext template', function (): void {
    app(VariableStore::class)->set('tax_rate', '0.2', 'string');

    $ctx = new FlowContext;

    expect($ctx->interpolate('rate is {{ globals.tax_rate }}'))->toBe('rate is 0.2');
});

it('exposes globals via the global() expression function', function (): void {
    app(VariableStore::class)->set('vat', 19, 'number');

    $result = app(ExpressionEvaluator::class)->evaluate("global('vat')");

    expect($result)->toBe(19);
});

// ---------------------------------------------------------------------------
// States: the primary alias for globals (additive — globals stays working)
// ---------------------------------------------------------------------------

it('interpolates {{ states.x }} in a FlowContext template', function (): void {
    app(VariableStore::class)->set('tax_rate', '0.2', 'string');

    $ctx = new FlowContext;

    expect($ctx->interpolate('rate is {{ states.tax_rate }}'))->toBe('rate is 0.2');
});

it('exposes states via the state() expression function', function (): void {
    app(VariableStore::class)->set('vat', 19, 'number');

    $result = app(ExpressionEvaluator::class)->evaluate("state('vat')");

    expect($result)->toBe(19);
});

it('exposes $states[] in a php function body', function (): void {
    config()->set('ai-page-builder.flow.allow_php_functions', true);
    app(VariableStore::class)->set('multiplier', 3, 'number');

    FlowFunction::create([
        'slug' => 'use_state', 'name' => 'Use State', 'runtime' => 'php',
        'body' => 'return (int) $args["n"] * (int) $states["multiplier"];',
    ]);

    $ctx = new FlowContext;
    app(FunctionNode::class)->run(
        ['type' => 'function', 'config' => ['function' => 'use_state', 'args' => ['n' => '4'], 'output' => 'out'], 'next' => []],
        $ctx,
    );

    expect($ctx->vars['out'])->toBe(12);
});

it('exposes states[] in an expression function body', function (): void {
    app(VariableStore::class)->set('rate', 2, 'number');

    FlowFunction::create([
        'slug' => 'expr_state', 'name' => 'Expr State', 'runtime' => 'expression',
        'body' => 'states["rate"] * args["n"]',
    ]);

    $ctx = new FlowContext;
    app(FunctionNode::class)->run(
        ['type' => 'function', 'config' => ['function' => 'expr_state', 'args' => ['n' => 5], 'output' => 'out'], 'next' => []],
        $ctx,
    );

    expect($ctx->vars['out'])->toBe(10);
});

// ---------------------------------------------------------------------------
// Flow integration: SetVariableNode persists a value
// ---------------------------------------------------------------------------

it('persists a value through SetVariableNode and reflects it in the store', function (): void {
    $ctx = new FlowContext(['amount' => '100']);

    $node = [
        'config' => [
            'key' => 'last_amount',
            'value' => '{{ input.amount }}',
            'type' => 'number',
            'output' => 'saved',
        ],
        'next' => ['n2'],
    ];

    $handler = app(SetVariableNode::class);
    $next = $handler->run($node, $ctx);

    // The chosen type applies consistently: the persisted State, the downstream
    // output var, AND the setState action pushed to the page all carry the typed
    // int 100 — not the raw string "100".
    $setState = collect($ctx->actions)->firstWhere('type', 'setState');

    expect(app(VariableStore::class)->get('last_amount'))->toBe(100)
        ->and($ctx->vars['saved'])->toBe(100)
        ->and($setState['value'])->toBe(100)
        ->and($next)->toBe(['n2']);
});
