<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\Nodes\FunctionNode;
use Andre\AiPageBuilder\Models\FlowFunction;

function phpNode(array $args = []): array
{
    return ['type' => 'function', 'config' => ['function' => 'calc', 'args' => $args, 'output' => 'out'], 'next' => []];
}

it('executes a php-runtime function when enabled', function (): void {
    config()->set('ai-page-builder.flow.allow_php_functions', true);
    FlowFunction::create([
        'slug' => 'calc', 'name' => 'Calc', 'runtime' => 'php',
        'body' => '$sum = (int) $args["a"] + (int) $args["b"]; return $sum * 2;',
    ]);

    $ctx = new FlowContext;
    app(FunctionNode::class)->run(phpNode(['a' => '3', 'b' => '4']), $ctx);

    expect($ctx->vars['out'])->toBe(14);
});

it('returns null for a php-runtime function when disabled', function (): void {
    config()->set('ai-page-builder.flow.allow_php_functions', false);
    FlowFunction::create([
        'slug' => 'calc', 'name' => 'Calc', 'runtime' => 'php',
        'body' => 'return 99;',
    ]);

    $ctx = new FlowContext;
    app(FunctionNode::class)->run(phpNode(), $ctx);

    expect($ctx->vars['out'])->toBeNull();
});
