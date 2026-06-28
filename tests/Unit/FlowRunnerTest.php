<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;
use Andre\AiPageBuilder\Flow\FlowRunner;
use Illuminate\Support\Facades\Http;

function fakeAi(): void
{
    app()->bind(AiInvoker::class, fn (): AiInvoker => new class implements AiInvoker
    {
        public function available(): bool
        {
            return true;
        }

        public function invoke(string $integration, array $args = []): string
        {
            return 'AI['.$integration.']:'.($args['brief'] ?? '');
        }
    });
}

it('runs ai_invoke then a result action, interpolating the output', function (): void {
    fakeAi();

    $def = [
        'start' => 'n1',
        'nodes' => [
            'n1' => ['type' => 'trigger', 'next' => ['n2']],
            'n2' => ['type' => 'ai_invoke', 'config' => ['integration' => 'page_builder', 'args' => ['brief' => '{{input.brief}}'], 'output' => 'ai'], 'next' => ['n3']],
            'n3' => ['type' => 'result', 'config' => ['actions' => [['type' => 'setHtml', 'target' => '#out', 'html' => '{{vars.ai}}']]]],
        ],
    ];

    $ctx = app(FlowRunner::class)->run($def, ['brief' => 'hello']);

    expect($ctx->vars['ai'])->toBe('AI[page_builder]:hello')
        ->and($ctx->actions)->toHaveCount(1)
        ->and($ctx->actions[0])->toMatchArray(['type' => 'setHtml', 'target' => '#out', 'html' => 'AI[page_builder]:hello']);
});

it('routes a condition to the true/false branch', function (): void {
    $def = fn (): array => [
        'start' => 'c',
        'nodes' => [
            'c' => ['type' => 'condition', 'config' => ['left' => '{{input.x}}', 'op' => 'not_empty'], 'next_true' => ['t'], 'next_false' => ['f']],
            't' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'TRUE']]]],
            'f' => ['type' => 'result', 'config' => ['actions' => [['type' => 'notify', 'message' => 'FALSE']]]],
        ],
    ];

    $runner = app(FlowRunner::class);
    expect($runner->run($def(), ['x' => 'yes'])->actions[0]['message'])->toBe('TRUE')
        ->and($runner->run($def(), ['x' => ''])->actions[0]['message'])->toBe('FALSE');
});

it('calls an http endpoint and stores the response', function (): void {
    Http::fake(['api.test/*' => Http::response(['ok' => true], 200)]);

    $def = [
        'start' => 'h',
        'nodes' => [
            'h' => ['type' => 'http_request', 'config' => ['method' => 'get', 'url' => 'https://api.test/ping', 'output' => 'h']],
        ],
    ];

    $ctx = app(FlowRunner::class)->run($def);

    expect($ctx->vars['h'])->toMatchArray(['ok' => true])
        ->and($ctx->vars['h_status'])->toBe(200);
});

it('drops disallowed result action types and skips unknown nodes', function (): void {
    $def = [
        'start' => 'n1',
        'nodes' => [
            'n1' => ['type' => 'definitely_unknown', 'next' => ['n2']],
            'n2' => ['type' => 'result', 'config' => ['actions' => [
                ['type' => 'evil', 'script' => 'alert(1)'],
                ['type' => 'redirect', 'url' => '/thanks'],
            ]]],
        ],
    ];

    // Unknown start node has no handler -> skipped, flow ends; nothing routes to n2.
    $ctx = app(FlowRunner::class)->run($def);
    expect($ctx->actions)->toBeEmpty();
});
