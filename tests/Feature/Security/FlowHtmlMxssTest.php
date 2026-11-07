<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\FlowHtml;

it('does not pass an SVG <style> mXSS payload from a {{{ raw }}} token', function (string $payload): void {
    $ctx = new FlowContext(['comment' => $payload]);
    $html = app(FlowHtml::class)->render('<div class="c">{{{ input.comment }}}</div>', $ctx);

    // A browser parses <style> inside <svg>/<math> as markup: any onerror / x-* kept
    // as "text" by libxml becomes a live attribute in the visitor's page.
    expect(strtolower($html))->not->toContain('onerror')
        ->and(strtolower($html))->not->toContain('x-data');
})->with([
    'svg style img' => '<svg><style><img src=x onerror=alert(document.domain)></style></svg>',
    'math style img' => '<math><style><img src=x onerror=alert(document.domain)></style></math>',
]);

it('does not pass the same payload through an escaped {{ token }} either (control)', function (): void {
    $ctx = new FlowContext(['comment' => '<svg><style><img src=x onerror=alert(1)></style></svg>']);
    $html = app(FlowHtml::class)->render('<div>{{ input.comment }}</div>', $ctx);
    expect($html)->not->toContain('<img');
});
