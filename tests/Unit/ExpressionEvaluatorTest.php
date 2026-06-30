<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Flow\ExpressionEvaluator;

it('evaluates a simple arithmetic expression', function (): void {
    $evaluator = new ExpressionEvaluator(new HelperRegistry);

    expect($evaluator->evaluate('1 + 2'))->toBe(3);
});

it('evaluates an expression with variable substitution', function (): void {
    $evaluator = new ExpressionEvaluator(new HelperRegistry);

    $result = $evaluator->evaluate(
        'args["price"] * qty',
        ['args' => ['price' => 10], 'qty' => 3]
    );

    expect($result)->toBe(30);
});

it('returns null and does not throw on a bad expression', function (): void {
    $evaluator = new ExpressionEvaluator(new HelperRegistry);

    $result = $evaluator->evaluate('this is not valid %%%');

    expect($result)->toBeNull();
});
