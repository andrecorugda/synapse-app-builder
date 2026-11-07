<?php

declare(strict_types=1);

/*
 * Regression tests for the HTTP Request node's SSRF guard in 2.0
 * (GHSA-hj3q-8gc4-6mpw): more internal ranges, unresolvable names refused, and
 * the connection pinned to the vetted addresses (no DNS rebinding).
 */

use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\Nodes\HttpRequestNode;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** The node with DNS answered from a fixed table instead of the network. */
function nodeResolving(array $answers): HttpRequestNode
{
    return new class($answers) extends HttpRequestNode
    {
        public function __construct(private readonly array $answers) {}

        protected function resolveHost(string $host): array
        {
            return $this->answers[$host] ?? [];
        }
    };
}

function guardedGet(HttpRequestNode $node, string $url): FlowContext
{
    $ctx = new FlowContext;
    $node->run(['type' => 'http_request', 'config' => ['method' => 'get', 'url' => $url, 'output' => 'res'], 'next' => []], $ctx);

    return $ctx;
}

it('blocks shared, NAT64 and IPv4-mapped addresses', function (string $url): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $ctx = guardedGet(app(HttpRequestNode::class), $url);

    expect($ctx->get('vars.res_status'))->toBe(0);
    Http::assertNothingSent();
})->with([
    'shared 100.64/10' => ['http://100.64.0.1/x'],
    'shared 100.127' => ['http://100.127.255.254/x'],
    'NAT64 loopback' => ['http://[64:ff9b::7f00:1]/x'],
    'NAT64 metadata' => ['http://[64:ff9b::a9fe:a9fe]/x'],
    'IPv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/x'],
]);

it('blocks a name that resolves to an internal address', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $ctx = guardedGet(nodeResolving(['rebind.test' => ['93.184.215.14', '100.100.100.200']]), 'http://rebind.test/x');

    expect($ctx->get('vars.res_status'))->toBe(0);
    Http::assertNothingSent();
});

it('blocks a name that does not resolve, so the transport never resolves it on its own', function (): void {
    Http::fake(['*' => Http::response([], 200)]);

    $ctx = guardedGet(nodeResolving([]), 'http://nxdomain-first.test/x');

    expect($ctx->get('vars.res_status'))->toBe(0);
    Http::assertNothingSent();
});

it('pins the connection to the addresses it vetted', function (): void {
    $options = null;
    Http::fake(function (Request $request, array $opts) use (&$options) {
        $options = $opts;

        return Http::response(['ok' => true], 200);
    });

    $ctx = guardedGet(nodeResolving(['api.partner.test' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']]), 'https://api.partner.test/v1');

    expect($ctx->get('vars.res_status'))->toBe(200)
        ->and($options['curl'][CURLOPT_RESOLVE] ?? null)
        ->toBe(['api.partner.test:443:93.184.215.14,[2606:2800:21f:cb07:6820:80da:af6b:8b2c]']);
});

it('still calls a public IP literal without a pin', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $ctx = guardedGet(app(HttpRequestNode::class), 'http://93.184.215.14/thing');

    expect($ctx->get('vars.res_status'))->toBe(200);
});
