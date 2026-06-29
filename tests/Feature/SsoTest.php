<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Auth\SocialAuthException;
use Andre\AiPageBuilder\Auth\SocialProviders;
use Andre\AiPageBuilder\Auth\SocialUserResolver;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Settings;
use Laravel\Socialite\Two\User as SocialiteUser;

/** Flip a runtime auth-policy / provider key through the Settings service. */
function ssoSetting(string $key, mixed $value): void
{
    app(Settings::class)->set($key, $value);
}

/** Give a provider working env/config credentials. */
function ssoCredentials(string $provider): void
{
    config([
        "ai-page-builder.auth.providers.{$provider}.client_id" => 'client-id',
        "ai-page-builder.auth.providers.{$provider}.client_secret" => 'client-secret',
    ]);
}

/** Enable a provider through the runtime Settings override. */
function ssoEnable(string $provider): void
{
    ssoSetting("auth.providers.{$provider}.enabled", true);
}

/** A fake Socialite identity (the contract the resolver receives). */
function ssoUser(string $id, ?string $email, string $name = 'Ada Lovelace'): SocialiteUser
{
    $user = new SocialiteUser;
    $user->id = $id;
    $user->name = $name;
    $user->nickname = 'ada';
    $user->email = $email;
    $user->avatar = null;
    $user->token = 'tok';

    return $user;
}

function resolver(): SocialUserResolver
{
    return app(SocialUserResolver::class);
}

function providers(): SocialProviders
{
    return app(SocialProviders::class);
}

// --- 1. SocialProviders::usable / usableList --------------------------------

it('is not usable when the provider is disabled', function (): void {
    ssoCredentials('google');
    // enabled flag never set → default false

    expect(providers()->usable('google'))->toBeFalse();
});

it('is not usable when enabled but missing credentials', function (): void {
    ssoEnable('google');
    config([
        'ai-page-builder.auth.providers.google.client_id' => null,
        'ai-page-builder.auth.providers.google.client_secret' => null,
    ]);

    expect(providers()->usable('google'))->toBeFalse();
});

it('is usable when enabled, credentialed, and socialite is installed', function (): void {
    ssoCredentials('google');
    ssoEnable('google');

    expect(providers()->socialiteAvailable())->toBeTrue();
    expect(providers()->usable('google'))->toBeTrue();
});

it('usableList returns only usable providers with key and label', function (): void {
    ssoCredentials('google');
    ssoEnable('google');
    // github enabled but no credentials → excluded
    ssoEnable('github');
    // microsoft left fully unconfigured → excluded

    expect(providers()->usableList())->toBe([
        ['key' => 'google', 'label' => 'Google'],
    ]);
});

// --- 2. Resolver: domain restriction (google / microsoft) -------------------

it('rejects a google email outside the allowed domains', function (): void {
    ssoSetting('auth.providers.google.allowed_domains', ['acme.com']);

    expect(fn () => resolver()->resolve('google', ssoUser('g1', 'x@other.com')))
        ->toThrow(SocialAuthException::class);

    expect(PbUser::query()->count())->toBe(0);
});

it('accepts a google email inside the allowed domains', function (): void {
    ssoSetting('auth.providers.google.allowed_domains', ['acme.com']);

    $user = resolver()->resolve('google', ssoUser('g1', 'x@acme.com'));

    expect($user)->toBeInstanceOf(PbUser::class);
    expect($user->email)->toBe('x@acme.com');
});

it('allows any domain when no allowed_domains are set', function (): void {
    $user = resolver()->resolve('google', ssoUser('g1', 'anyone@whatever.io'));

    expect($user->email)->toBe('anyone@whatever.io');
});

// --- 3. Resolver: github org restriction ------------------------------------

it('rejects a github user not in an allowed org', function (): void {
    ssoSetting('auth.providers.github.allowed_orgs', ['acme-inc']);

    expect(fn () => resolver()->resolve('github', ssoUser('h1', 'dev@example.com'), ['other']))
        ->toThrow(SocialAuthException::class);

    expect(PbUser::query()->count())->toBe(0);
});

it('accepts a github user in an allowed org', function (): void {
    ssoSetting('auth.providers.github.allowed_orgs', ['acme-inc']);

    $user = resolver()->resolve('github', ssoUser('h1', 'dev@example.com'), ['acme-inc']);

    expect($user->email)->toBe('dev@example.com');
});

it('allows any github user when no allowed_orgs are set', function (): void {
    $user = resolver()->resolve('github', ssoUser('h1', 'dev@example.com'), []);

    expect($user->email)->toBe('dev@example.com');
});

// --- 4. Resolver: mapping, dedup, linking, missing email --------------------

it('creates a PbUser with provider, provider_id, no password and a verified email', function (): void {
    $user = resolver()->resolve('google', ssoUser('g42', 'new@example.com'));

    expect($user->getAttribute('provider'))->toBe('google');
    expect($user->getAttribute('provider_id'))->toBe('g42');
    expect($user->getAttribute('password'))->toBeNull();
    expect($user->getAttribute('email_verified_at'))->not->toBeNull();
});

it('returns the same user (no duplicate) for the same provider id', function (): void {
    $first = resolver()->resolve('google', ssoUser('g42', 'new@example.com'));
    $second = resolver()->resolve('google', ssoUser('g42', 'new@example.com'));

    expect($second->id)->toBe($first->id);
    expect(PbUser::query()->count())->toBe(1);
});

it('links an existing account with the same email', function (): void {
    $existing = PbUser::query()->create([
        'name' => 'Existing',
        'email' => 'existing@example.com',
        'password' => 'local-pass',
        'is_active' => true,
        'status' => PbUser::STATUS_ACTIVE,
    ]);

    $resolved = resolver()->resolve('google', ssoUser('g99', 'existing@example.com'));

    expect($resolved->id)->toBe($existing->id);
    expect($resolved->getAttribute('provider'))->toBe('google');
    expect($resolved->getAttribute('provider_id'))->toBe('g99');
    expect(PbUser::query()->count())->toBe(1);
});

it('rejects a sign-in when the provider shares no email', function (): void {
    expect(fn () => resolver()->resolve('google', ssoUser('g1', null)))
        ->toThrow(SocialAuthException::class);

    expect(PbUser::query()->count())->toBe(0);
});

// --- 5. Onboarding policy ---------------------------------------------------

it('creates a pending SSO user under the approval registration mode', function (): void {
    ssoSetting('auth.registration_mode', 'approval');

    $user = resolver()->resolve('google', ssoUser('g1', 'pending@example.com'));

    expect($user->getAttribute('status'))->toBe(PbUser::STATUS_PENDING);
    expect($user->canLogin())->toBeFalse();
});

it('creates an active SSO user under the open registration mode', function (): void {
    ssoSetting('auth.registration_mode', 'open');

    $user = resolver()->resolve('google', ssoUser('g1', 'open@example.com'));

    expect($user->getAttribute('status'))->toBe(PbUser::STATUS_ACTIVE);
    expect($user->canLogin())->toBeTrue();
});

it('assigns the configured default role to a new SSO user', function (): void {
    $role = PbRole::query()->create(['name' => 'Member', 'slug' => 'member', 'is_admin' => false]);
    ssoSetting('auth.default_role', 'member');
    ssoSetting('auth.registration_mode', 'open');

    $user = resolver()->resolve('google', ssoUser('g1', 'member@example.com'));

    expect($user->getAttribute('role_id'))->toBe($role->id);
});

// --- 6. Route gating: not-usable provider degrades gracefully ---------------

it('redirects /login/sso/{provider} to login with an error when not usable', function (): void {
    // google is a valid driver but disabled / uncredentialed → not usable.
    $this->from('/login')
        ->get('/login/sso/google')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
});
