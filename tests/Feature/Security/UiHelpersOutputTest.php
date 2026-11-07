<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Capabilities\HelperRegistry;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FlowRuntime;

/*
 * The ui_modal / ui_redirect expression helpers queue page actions directly,
 * so they must apply the same output rules as setHtml / redirect results.
 */

function runUiHelper(string $key, mixed ...$args): FlowContext
{
    $context = new FlowContext;
    app(FlowRuntime::class)->setContext($context);
    app(HelperRegistry::class)->call($key, ...$args);
    app(FlowRuntime::class)->setContext(null);

    return $context;
}

it('sanitises ui_modal html built from an expression', function (): void {
    $context = runUiHelper('ui_modal', 'open', '#m', '<svg><style><img src=x onerror=alert(1)></style></svg><a href="javascript:alert(1)">x</a><p>ok</p>');

    $html = (string) ($context->actions[0]['html'] ?? '');
    expect($context->actions)->toHaveCount(1)
        ->and($html)->not->toContain('onerror')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->toContain('<p>ok</p>');
});

it('drops a ui_redirect to a script URL and keeps a normal one', function (): void {
    expect(runUiHelper('ui_redirect', 'javascript:alert(document.domain)')->actions)->toBe([]);
    expect(runUiHelper('ui_redirect', '/p/thanks')->actions)->toBe([['type' => 'redirect', 'url' => '/p/thanks']]);
});
