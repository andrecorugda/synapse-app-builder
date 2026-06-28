<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\ExpressionEvaluator;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FunctionRegistry;
use Andre\AiPageBuilder\Flow\Nodes\FunctionNode;
use Andre\AiPageBuilder\Models\FlowFunction;

// ---------------------------------------------------------------------------
// Expression runtime
// ---------------------------------------------------------------------------

it('runs an expression function and stores the result in context', function (): void {
    // Seed a FlowFunction with a string-concat expression.
    // Args are interpolated strings ("2", "3") so we concatenate rather than
    // add — this keeps the test deterministic regardless of ExpressionLanguage
    // type coercion across Symfony versions.
    FlowFunction::create([
        'slug' => 'concat-fn',
        'name' => 'Concat A and B',
        'runtime' => 'expression',
        'body' => 'args["a"] ~ args["b"]',
    ]);

    $ctx = new FlowContext(['dummy' => true]);

    $node = [
        'config' => [
            'function' => 'concat-fn',
            'args' => ['a' => '2', 'b' => '3'],
            'output' => 'sum',
        ],
        'next' => ['n2'],
    ];

    $registry = app(FunctionRegistry::class);
    $evaluator = app(ExpressionEvaluator::class);
    $handler = new FunctionNode($registry, $evaluator);

    $nextIds = $handler->run($node, $ctx);

    expect($ctx->vars['sum'])->toBe('23')
        ->and($nextIds)->toBe(['n2']);
});

it('returns null when the function slug does not exist', function (): void {
    $ctx = new FlowContext;
    $node = [
        'config' => [
            'function' => 'no-such-slug',
            'args' => [],
            'output' => 'out',
        ],
    ];

    $handler = new FunctionNode(app(FunctionRegistry::class), app(ExpressionEvaluator::class));
    $handler->run($node, $ctx);

    expect($ctx->vars['out'])->toBeNull();
});

// ---------------------------------------------------------------------------
// Callable runtime
// ---------------------------------------------------------------------------

it('runs a registered callable and stores the result in context', function (): void {
    /** @var FunctionRegistry $registry */
    $registry = app(FunctionRegistry::class);
    $registry->register('upper', fn (array $args): string => strtoupper((string) ($args['text'] ?? '')));

    FlowFunction::create([
        'slug' => 'upper-fn',
        'name' => 'Uppercase',
        'runtime' => 'callable',
        'body' => 'upper',
    ]);

    $ctx = new FlowContext;
    $node = [
        'config' => [
            'function' => 'upper-fn',
            'args' => ['text' => 'hi'],
            'output' => 'r',
        ],
        'next' => [],
    ];

    $handler = new FunctionNode($registry, app(ExpressionEvaluator::class));
    $handler->run($node, $ctx);

    expect($ctx->vars['r'])->toBe('HI');
});

it('returns null when a callable runtime key is not registered', function (): void {
    FlowFunction::create([
        'slug' => 'missing-cb',
        'name' => 'Missing',
        'runtime' => 'callable',
        'body' => 'not-registered',
    ]);

    $ctx = new FlowContext;
    $node = [
        'config' => [
            'function' => 'missing-cb',
            'args' => [],
            'output' => 'out',
        ],
    ];

    $handler = new FunctionNode(app(FunctionRegistry::class), app(ExpressionEvaluator::class));
    $handler->run($node, $ctx);

    expect($ctx->vars['out'])->toBeNull();
});
