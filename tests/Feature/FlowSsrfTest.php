<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\Nodes\HttpRequestNode;
use Andre\AiPageBuilder\Flow\Nodes\RecordNode;
use Andre\AiPageBuilder\Models\Credential;
use Andre\AiPageBuilder\Models\PbModel;
use Andre\AiPageBuilder\Services\Data\RecordQuery;
use Illuminate\Support\Facades\Http;

// ---- C2: HttpRequestNode SSRF guard ----------------------------------------

function httpNode(string $url, array $extra = []): array
{
    return ['type' => 'http_request', 'config' => array_merge([
        'method' => 'get', 'url' => $url, 'output' => 'res',
    ], $extra), 'next' => []];
}

it('blocks SSRF to loopback / link-local / metadata + non-http schemes', function (string $url): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $ctx = new FlowContext;

    app(HttpRequestNode::class)->run(httpNode($url), $ctx);

    expect($ctx->get('vars.res'))->toBeNull()
        ->and($ctx->get('vars.res_status'))->toBe(0);
    Http::assertNothingSent();
})->with([
    'loopback' => ['http://127.0.0.1/x'],
    'metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'private 10/8' => ['http://10.0.0.5/internal'],
    'private 192' => ['http://192.168.1.1/admin'],
    'file scheme' => ['file:///etc/passwd'],
    'gopher scheme' => ['gopher://127.0.0.1:6379/'],
]);

it('allows a public host', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $ctx = new FlowContext;

    // 93.184.215.14 is a public IP literal (example.com) — skips DNS, passes the guard.
    app(HttpRequestNode::class)->run(httpNode('http://93.184.215.14/thing'), $ctx);

    expect($ctx->get('vars.res_status'))->toBe(200);
    Http::assertSent(fn ($r): bool => str_contains($r->url(), '93.184.215.14'));
});

it('does NOT attach a credential when the URL is blocked', function (): void {
    Http::fake(['*' => Http::response([], 200)]);
    Credential::query()->create([
        'name' => 'Svc', 'key' => 'svc', 'type' => 'bearer', 'secret' => 'tok999',
    ]);
    $ctx = new FlowContext;

    app(HttpRequestNode::class)->run(httpNode('http://169.254.169.254/', ['credential' => 'svc']), $ctx);

    Http::assertNothingSent(); // secret never leaves the box
    expect($ctx->get('vars.res'))->toBeNull();
});

it('enforces an explicit host allow-list', function (): void {
    config()->set('ai-page-builder.flow.http_allowed_hosts', ['api.allowed.test']);
    Http::fake(['*' => Http::response([], 200)]);
    $ctx = new FlowContext;

    app(HttpRequestNode::class)->run(httpNode('http://93.184.215.14/x'), $ctx); // public but off-list
    Http::assertNothingSent();
    expect($ctx->get('vars.res'))->toBeNull();
});

// ---- C2: RecordNode structural-field lockdown ------------------------------

it('does NOT let caller input redirect a record op to another collection', function (): void {
    app(BuildPlanApplier::class)->apply(['collections' => [
        ['key' => 'secrets', 'name' => 'Secrets', 'fields' => [
            ['key' => 'title', 'label' => 'Title', 'type' => 'string'],
        ]],
    ]]);
    $secrets = PbModel::query()->where('key', 'secrets')->firstOrFail();
    app(RecordQuery::class)->create($secrets, ['title' => 'classified']);

    // A public flow whose author left model as an interpolation token: the caller
    // supplies input.target='secrets' trying to read it. The structural `model`
    // is NOT interpolated, so it stays the literal token → no collection → null.
    $ctx = new FlowContext(['target' => 'secrets']);
    app(RecordNode::class)->run([
        'type' => 'record',
        'config' => ['model' => '{{ input.target }}', 'operation' => 'list', 'output' => 'out'],
        'next' => [],
    ], $ctx);

    expect($ctx->get('vars.out'))->toBeNull(); // did NOT read the secrets collection

    // Control: an author-fixed literal model still works (value fields interpolate).
    $ctx2 = new FlowContext;
    app(RecordNode::class)->run([
        'type' => 'record',
        'config' => ['model' => 'secrets', 'operation' => 'list', 'output' => 'out'],
        'next' => [],
    ], $ctx2);

    expect($ctx2->get('vars.out'))->toBeArray()->toHaveCount(1);
});
