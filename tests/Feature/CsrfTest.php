<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Middleware\EnsureDataApiSameOrigin;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * H3 — the data API carries no Laravel CSRF token, so EnsureDataApiSameOrigin
 * guards cookie-authenticated writes with an origin check. The middleware is
 * exercised directly (as ApiTokenTest does for ResolveApiToken) because the data
 * API middleware stack is assembled at provider-boot time, before a per-test
 * config override could take effect.
 */

/**
 * Run a crafted request through the middleware; returns the status code the
 * middleware produced (200 = passed through to the next handler).
 *
 * @param  array<string,string>  $headers
 */
function runSameOrigin(string $method, array $headers = [], string $host = 'app.test'): int
{
    $request = Request::create('https://'.$host.'/api/pb/things', $method);
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    $response = (new EnsureDataApiSameOrigin)->handle(
        $request,
        fn (): Response => new Response('ok', 200),
    );

    return $response->getStatusCode();
}

it('rejects a cross-origin cookie write', function (): void {
    expect(runSameOrigin('POST', ['Origin' => 'https://evil.example']))->toBe(403);
});

it('allows a same-origin cookie write (Origin)', function (): void {
    expect(runSameOrigin('POST', ['Origin' => 'https://app.test']))->toBe(200);
});

it('allows a same-origin cookie write (Referer fallback)', function (): void {
    expect(runSameOrigin('POST', ['Referer' => 'https://app.test/dashboard']))->toBe(200);
});

it('allows a bearer-token write regardless of origin', function (): void {
    expect(runSameOrigin('POST', [
        'Origin' => 'https://evil.example',
        'Authorization' => 'Bearer some-token',
    ]))->toBe(200);
});

it('lets read-only requests through without an origin', function (): void {
    expect(runSameOrigin('GET'))->toBe(200);
});

it('rejects a cookie write carrying neither Origin nor Referer', function (): void {
    expect(runSameOrigin('POST'))->toBe(403);
});

it('rejects a cross-origin cookie write on every mutating verb', function (string $method): void {
    expect(runSameOrigin($method, ['Origin' => 'https://evil.example']))->toBe(403);
})->with(['PUT', 'PATCH', 'DELETE']);
