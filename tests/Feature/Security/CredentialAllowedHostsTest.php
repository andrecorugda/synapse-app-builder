<?php

declare(strict_types=1);

/*
 * A stored Credential is sent only to the hosts listed on it. A builder who
 * may edit flows but not secrets (no manage-secrets) can't point an HTTP
 * Request node at a host they own and receive the secret.
 */

use Andre\AiPageBuilder\Console\CredentialHostsCommand;
use Andre\AiPageBuilder\Filament\Resources\CredentialResource;
use Andre\AiPageBuilder\Filament\Resources\CredentialResource\Pages\EditCredential;
use Andre\AiPageBuilder\Flow\FlowContext;
use Andre\AiPageBuilder\Flow\Nodes\HttpRequestNode;
use Andre\AiPageBuilder\Models\Credential;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Services\CredentialHosts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** A public api-trigger flow plan with a single node after the trigger. */
function credentialHostsFlowPlan(string $slug, array $node): array
{
    return ['flows' => [[
        'slug' => $slug, 'name' => $slug, 'trigger_type' => 'api', 'is_public' => true,
        'definition' => [
            'start' => 't',
            'nodes' => [
                't' => ['type' => 'trigger', 'next' => ['n']],
                'n' => $node,
            ],
        ],
    ]]];
}

/** The HTTP node with DNS answered by a public address (no network). */
function credentialHostsNode(): HttpRequestNode
{
    return new class extends HttpRequestNode
    {
        public function __construct() {}

        protected function resolveHost(string $host): array
        {
            return ['93.184.216.34'];
        }
    };
}

function credentialHostsCall(string $url, string $credential = 'stripe'): FlowContext
{
    $ctx = new FlowContext;
    credentialHostsNode()->run(['type' => 'http_request', 'config' => ['method' => 'get', 'url' => $url, 'credential' => $credential, 'output' => 'res'], 'next' => []], $ctx);

    return $ctx;
}

/** @return list<string> the Authorization headers of every recorded request */
function credentialHostsSentAuth(): array
{
    $out = [];
    foreach (Http::recorded() as [$request]) {
        /** @var HttpRequest $request */
        $out[] = implode(',', $request->header('Authorization'));
    }

    return $out;
}

it('A1: a builder denied manage-secrets cannot get a stored credential sent to a host of their choice', function (): void {
    bootSynapsePanel();
    Gate::define('synapse.manage-secrets', fn (): bool => false);
    Credential::query()->create(['name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk_live_EXFIL_42']);

    $this->actingAs(synapseAdmin());
    expect(CredentialResource::canAccess())->toBeFalse();

    $this->postJson('/ai-page-builder/ai-chat/apply', ['plan' => credentialHostsFlowPlan('exfil', [
        'type' => 'http_request',
        'config' => ['method' => 'GET', 'url' => 'https://example.com/collect', 'credential' => 'stripe', 'output' => 'r'],
    ])])->assertOk();

    Http::fake(['*' => Http::response(['ok' => true])]);
    auth()->logout();
    $this->postJson('/pb-flow/exfil', ['input' => []]);

    expect(implode('|', credentialHostsSentAuth()))->not->toContain('sk_live_EXFIL_42');
});

it('sends the credential to an allowed host', function (string $pattern, string $url): void {
    Credential::query()->create(['name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk_ok', 'allowed_hosts' => [$pattern]]);
    Http::fake(['*' => Http::response(['ok' => true])]);

    $ctx = credentialHostsCall($url);

    expect($ctx->vars['res_status'])->toBe(200)
        ->and(credentialHostsSentAuth())->toBe(['Bearer sk_ok']);
})->with([
    'exact' => ['api.stripe.com', 'https://api.stripe.com/v1/charges'],
    'exact, any case and port' => ['api.stripe.com', 'https://API.Stripe.com:8443/v1'],
    'wildcard subdomain' => ['*.stripe.com', 'https://api.stripe.com/v1'],
    'wildcard deeper subdomain' => ['*.stripe.com', 'https://eu.api.stripe.com/v1'],
]);

it('refuses the request when the host is not allowed', function (string $pattern, string $url): void {
    Credential::query()->create(['name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk_no', 'allowed_hosts' => [$pattern]]);
    Http::fake(['*' => Http::response(['ok' => true])]);

    $ctx = credentialHostsCall($url);

    Http::assertNothingSent();
    expect($ctx->vars['res'])->toBeNull()->and($ctx->vars['res_status'])->toBe(0);
})->with([
    'other host' => ['api.stripe.com', 'https://attacker.test/collect'],
    'look-alike suffix' => ['api.stripe.com', 'https://api.stripe.com.attacker.test/'],
    'wildcard does not match the bare domain' => ['*.stripe.com', 'https://stripe.com/'],
    'wildcard look-alike' => ['*.stripe.com', 'https://evilstripe.com/'],
    'userinfo trick' => ['api.stripe.com', 'https://api.stripe.com@attacker.test/'],
]);

it('never sends a credential that has no allowed hosts', function (): void {
    Credential::query()->create(['name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk_empty']);
    Http::fake(['*' => Http::response(['ok' => true])]);

    credentialHostsCall('https://api.stripe.com/v1');

    Http::assertNothingSent();
});

it('still calls without auth when the credential key does not exist', function (): void {
    Http::fake(['*' => Http::response(['ok' => true])]);

    $ctx = credentialHostsCall('https://api.stripe.com/v1', 'missing');

    expect($ctx->vars['res_status'])->toBe(200)->and(credentialHostsSentAuth())->toBe(['']);
});

it('cleans the host list it stores', function (): void {
    $credential = Credential::query()->create(['name' => 'S', 'key' => 's', 'type' => 'bearer', 'secret' => 'x', 'allowed_hosts' => [
        ' API.Stripe.com ', 'https://hooks.example.com/path', '*.example.org', 'api.stripe.com', '*.com', 'not a host', '',
    ]]);

    expect($credential->fresh()->allowed_hosts)->toBe(['api.stripe.com', 'hooks.example.com', '*.example.org']);
});

it('validates allowed host patterns', function (string $entry, bool $valid): void {
    expect(CredentialHosts::isValidPattern($entry))->toBe($valid);
})->with([
    ['api.example.com', true],
    ['*.example.com', true],
    ['localhost', true],
    ['93.184.216.34', true],
    ['*.com', false],
    ['*', false],
    ['exa mple.com', false],
    ['example.com/path', false],
]);

it('saves allowed hosts from the Credentials screen', function (): void {
    bootSynapsePanel();
    Credential::query()->create(['name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk_live_keep']);
    $this->actingAs(synapseAdmin());

    Livewire::test(EditCredential::class, ['record' => 'stripe'])
        ->fillForm(['allowed_hosts' => ['api.stripe.com', '*.stripe.network']])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Credential::query()->where('key', 'stripe')->firstOrFail()->allowed_hosts)->toBe(['api.stripe.com', '*.stripe.network']);

    Livewire::test(EditCredential::class, ['record' => 'stripe'])
        ->fillForm(['allowed_hosts' => ['not a host']])
        ->call('save')
        ->assertHasFormErrors();
});

it('the upgrade migration allows the hosts existing flows already call with each credential', function (): void {
    Credential::query()->create(['name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk']);
    Credential::query()->create(['name' => 'Unused', 'key' => 'unused', 'type' => 'bearer', 'secret' => 'sk']);
    Flow::create(['slug' => 'charge', 'name' => 'Charge', 'trigger_type' => 'api', 'is_active' => true, 'definition' => [
        'start' => 'l', 'nodes' => [
            'l' => ['type' => 'loop', 'config' => ['over' => 'input.items', 'body' => ['start' => 'h', 'nodes' => [
                'h' => ['type' => 'http_request', 'config' => ['url' => 'https://api.stripe.com/v1/charges', 'credential' => 'stripe']],
            ]]], 'next' => ['h2']],
            'h2' => ['type' => 'http_request', 'config' => ['url' => 'https://{{ input.host }}/x', 'credential' => 'stripe']],
        ],
    ]]);

    $migration = require __DIR__.'/../../../database/migrations/add_allowed_hosts_to_page_builder_credentials_table.php';
    $migration->down();
    expect(Schema::connection(Andre\AiPageBuilder\Support\Schema::connection())->hasColumn(Andre\AiPageBuilder\Support\Schema::table('credentials'), 'allowed_hosts'))->toBeFalse();
    $migration->up();

    expect(Credential::query()->where('key', 'stripe')->firstOrFail()->allowed_hosts)->toBe(['api.stripe.com'])
        ->and(Credential::query()->where('key', 'unused')->firstOrFail()->allowed_hosts)->toBe([]);
});

it('lists and changes allowed hosts from the console', function (): void {
    // Registered by the service provider; registered here too so the test
    // does not depend on it.
    $this->app->make(Kernel::class)->registerCommand($this->app->make(CredentialHostsCommand::class));
    Credential::query()->create(['name' => 'Stripe', 'key' => 'stripe', 'type' => 'bearer', 'secret' => 'sk']);
    Flow::create(['slug' => 'charge', 'name' => 'Charge', 'trigger_type' => 'api', 'is_active' => true, 'definition' => [
        'start' => 'h', 'nodes' => ['h' => ['type' => 'http_request', 'config' => ['url' => 'https://api.stripe.com/v1', 'credential' => 'stripe']]],
    ]]);

    $this->artisan('ai-page-builder:credential-hosts')->expectsOutputToContain('api.stripe.com')->assertSuccessful();
    expect(Credential::query()->firstOrFail()->allowed_hosts)->toBe([]);

    $this->artisan('ai-page-builder:credential-hosts', ['--from-flows' => true])->assertSuccessful();
    expect(Credential::query()->firstOrFail()->allowed_hosts)->toBe(['api.stripe.com']);

    $this->artisan('ai-page-builder:credential-hosts', ['key' => 'stripe', '--add' => '*.stripe.network', '--remove' => 'api.stripe.com'])->assertSuccessful();
    expect(Credential::query()->firstOrFail()->allowed_hosts)->toBe(['*.stripe.network']);

    $this->artisan('ai-page-builder:credential-hosts', ['--add' => 'x.test'])->assertFailed();
});
