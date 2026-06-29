<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\FlowRunner;
use Andre\AiPageBuilder\Models\Credential;
use Andre\AiPageBuilder\Services\CredentialStore;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// The base TestCase requires migrations explicitly by path; the credentials
// table is registered by the host app, so load it here for the suite.
beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/create_page_builder_credentials_table.php')->up();
});

it('stores the secret encrypted and returns it decrypted via the accessor', function (): void {
    $credential = Credential::query()->create([
        'name' => 'Acme API',
        'key' => 'acme',
        'type' => 'bearer',
        'secret' => 'super-secret-token',
    ]);

    // The accessor round-trips plaintext...
    expect($credential->secret)->toBe('super-secret-token')
        ->and($credential->fresh()->secret)->toBe('super-secret-token');

    // ...but the raw column is ciphertext, never the plaintext.
    $raw = DB::connection(PbSchema::connection())
        ->table(PbSchema::table('credentials'))
        ->where('key', 'acme')
        ->value('secret');

    expect($raw)->not->toBe('super-secret-token')
        ->and($raw)->not->toContain('super-secret-token');
});

it('builds a Bearer header', function (): void {
    Credential::query()->create([
        'name' => 'Bearer', 'key' => 'b', 'type' => 'bearer', 'secret' => 'tok123',
    ]);

    expect(app(CredentialStore::class)->headers('b'))
        ->toBe(['Authorization' => 'Bearer tok123']);
});

it('builds an API-key header with the configured header name', function (): void {
    Credential::query()->create([
        'name' => 'Key', 'key' => 'k', 'type' => 'api_key', 'secret' => 'abc',
        'meta' => ['header_name' => 'X-Custom-Key'],
    ]);

    expect(app(CredentialStore::class)->headers('k'))
        ->toBe(['X-Custom-Key' => 'abc']);
});

it('defaults the API-key header name to X-API-Key when meta is absent', function (): void {
    Credential::query()->create([
        'name' => 'Key', 'key' => 'k2', 'type' => 'api_key', 'secret' => 'abc',
    ]);

    expect(app(CredentialStore::class)->headers('k2'))
        ->toBe(['X-API-Key' => 'abc']);
});

it('builds a Basic auth header from username + secret', function (): void {
    Credential::query()->create([
        'name' => 'Basic', 'key' => 'ba', 'type' => 'basic', 'secret' => 'pw',
        'meta' => ['username' => 'user'],
    ]);

    expect(app(CredentialStore::class)->headers('ba'))
        ->toBe(['Authorization' => 'Basic '.base64_encode('user:pw')]);
});

it('returns no headers for an unknown or empty key', function (): void {
    expect(app(CredentialStore::class)->headers('nope'))->toBe([])
        ->and(app(CredentialStore::class)->headers(''))->toBe([]);
});

it('merges a credential\'s headers into the HTTP request, taking precedence over inline headers', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    Credential::query()->create([
        'name' => 'Bearer', 'key' => 'svc', 'type' => 'bearer', 'secret' => 'tok999',
    ]);

    $def = [
        'start' => 'h',
        'nodes' => [
            'h' => ['type' => 'http_request', 'config' => [
                'method' => 'get',
                'url' => 'https://api.example.com/thing',
                'headers' => ['Authorization' => 'Bearer inline-should-lose', 'X-Trace' => '1'],
                'credential' => 'svc',
                'output' => 'res',
            ]],
        ],
    ];

    app(FlowRunner::class)->run($def);

    Http::assertSent(function ($request): bool {
        return $request->header('Authorization')[0] === 'Bearer tok999'
            && $request->header('X-Trace')[0] === '1';
    });
});

it('leaves the request unchanged when no credential is configured', function (): void {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    $def = [
        'start' => 'h',
        'nodes' => [
            'h' => ['type' => 'http_request', 'config' => [
                'method' => 'get',
                'url' => 'https://api.example.com/thing',
                'headers' => ['X-Trace' => '1'],
                'output' => 'res',
            ]],
        ],
    ];

    app(FlowRunner::class)->run($def);

    Http::assertSent(fn ($request): bool => $request->header('X-Trace')[0] === '1'
        && $request->hasHeader('Authorization') === false);
});
