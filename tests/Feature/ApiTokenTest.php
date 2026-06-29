<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Middleware\ResolveApiToken;
use Andre\AiPageBuilder\Models\PbApiToken;
use Andre\AiPageBuilder\Models\PbUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The api_tokens table isn't in the package's default test migrations. */
beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/create_page_builder_api_tokens_table.php')->up();
});

function pbApiTokenUser(array $attrs = []): PbUser
{
    return PbUser::query()->create(array_merge([
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'secret-pass',
        'is_active' => true,
    ], $attrs));
}

/** Run a Bearer string through the middleware; returns the resolved pb user (or null) and the response. */
function runResolveApiToken(?string $bearer): array
{
    $request = Request::create('/api/pb/things', 'GET');
    if ($bearer !== null) {
        $request->headers->set('Authorization', 'Bearer '.$bearer);
    }

    $response = (new ResolveApiToken)->handle($request, fn (Request $r) => new JsonResponse(['ok' => true]));

    return [
        'response' => $response,
        'user' => Auth::guard('pb')->user(),
    ];
}

it('stores a token as its sha256 hash and matches on lookup', function (): void {
    $result = PbApiToken::generate('ci');

    $plain = $result['plain_text'];
    $model = $result['token'];

    expect($model->token)->toBe(hash('sha256', $plain))
        ->and($model->token)->not->toBe($plain)
        ->and(PbApiToken::findToken($plain)?->is($model))->toBeTrue();
});

it('sets the pb user for a valid bearer token', function (): void {
    $user = pbApiTokenUser();
    $plain = PbApiToken::generate('valid', $user->id)['plain_text'];

    ['response' => $response, 'user' => $resolved] = runResolveApiToken($plain);

    expect($response->getStatusCode())->toBe(200)
        ->and($resolved?->getAuthIdentifier())->toBe($user->id);
});

it('401s an invalid bearer token', function (): void {
    ['response' => $response, 'user' => $resolved] = runResolveApiToken('not-a-real-token');

    expect($response->getStatusCode())->toBe(401)
        ->and($resolved)->toBeNull();
});

it('passes through with no bearer token', function (): void {
    ['response' => $response, 'user' => $resolved] = runResolveApiToken(null);

    expect($response->getStatusCode())->toBe(200)
        ->and($resolved)->toBeNull();
});

it('rejects an expired token as invalid', function (): void {
    $user = pbApiTokenUser();
    $plain = PbApiToken::generate('expired', $user->id, null, now()->subDay())['plain_text'];

    expect(PbApiToken::findToken($plain))->toBeNull();

    ['response' => $response] = runResolveApiToken($plain);
    expect($response->getStatusCode())->toBe(401);
});

it('leaves the guard unauthenticated for an ownerless token', function (): void {
    $plain = PbApiToken::generate('ownerless')['plain_text'];

    ['response' => $response, 'user' => $resolved] = runResolveApiToken($plain);

    expect($response->getStatusCode())->toBe(200)
        ->and($resolved)->toBeNull();
});

it('records last_used_at when a token authenticates', function (): void {
    $user = pbApiTokenUser();
    $result = PbApiToken::generate('used', $user->id);

    expect($result['token']->last_used_at)->toBeNull();

    runResolveApiToken($result['plain_text']);

    expect($result['token']->fresh()->last_used_at)->not->toBeNull();
});
