<?php

declare(strict_types=1);

/*
 * End-user auth hardening (GHSA-hj3q-8gc4-6mpw, low-severity items):
 *  L1 logout ends the whole session and rotates the CSRF token;
 *  L2 turning 2FA off needs the current password (or a code, if password-less);
 *  L3 SSO sign-in goes through the 2FA challenge like a password sign-in;
 *  L4 the PHP lint endpoint leaves no temp files behind.
 */

use Andre\AiPageBuilder\Auth\TwoFactorService;
use Andre\AiPageBuilder\Http\Controllers\TwoFactorController;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Settings;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteServiceProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use PragmaRX\Google2FA\Google2FA;

function hardeningUser(array $attrs = []): PbUser
{
    return PbUser::query()->create(array_merge([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'secret-pass',
        'is_active' => true,
        'status' => PbUser::STATUS_ACTIVE,
    ], $attrs));
}

/** Enrol TOTP for the user and return the secret. */
function hardeningEnrolTotp(PbUser $user): string
{
    $tfa = app(TwoFactorService::class);
    $secret = $tfa->beginTotp($user)['secret'];
    $tfa->confirm($user, (new Google2FA)->getCurrentOtp($secret));

    return $secret;
}

it('L1: logout invalidates the session and issues a fresh CSRF token', function (): void {
    $user = hardeningUser();
    $this->actingAs($user, 'pb')->withSession(['pb_marker' => 'kept-by-old-session']);
    $oldToken = session()->token();

    $this->post('/pb-logout')->assertRedirect('/login');

    expect(auth('pb')->check())->toBeFalse()
        ->and(session()->has('pb_marker'))->toBeFalse()
        ->and(session()->token())->not->toBe($oldToken);
});

it('L2: turning two-factor off requires the current password', function (): void {
    $user = hardeningUser();
    hardeningEnrolTotp($user);
    $this->actingAs($user, 'pb');

    $this->post('/login/two-factor/disable')->assertSessionHasErrors('current_password');
    $this->post('/login/two-factor/disable', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    expect(app(TwoFactorService::class)->isEnabled($user->refresh()))->toBeTrue();

    $this->post('/login/two-factor/disable', ['current_password' => 'secret-pass'])->assertSessionHasNoErrors();
    expect(app(TwoFactorService::class)->isEnabled($user->refresh()))->toBeFalse();
});

it('L2: a password-less (SSO) user turns two-factor off with a current code', function (): void {
    $user = hardeningUser(['password' => null, 'provider' => 'google', 'provider_id' => 'g-1']);
    $secret = hardeningEnrolTotp($user);
    $this->actingAs($user, 'pb');

    $this->post('/login/two-factor/disable', ['code' => '000000'])->assertSessionHasErrors('code');
    expect(app(TwoFactorService::class)->isEnabled($user->refresh()))->toBeTrue();

    $this->post('/login/two-factor/disable', ['code' => (new Google2FA)->getCurrentOtp($secret)])->assertSessionHasNoErrors();
    expect(app(TwoFactorService::class)->isEnabled($user->refresh()))->toBeFalse();
});

it('L3: SSO sign-in of an enrolled user goes to the two-factor challenge', function (): void {
    app()->register(SocialiteServiceProvider::class);
    config([
        'ai-page-builder.auth.providers.google.client_id' => 'client-id',
        'ai-page-builder.auth.providers.google.client_secret' => 'client-secret',
    ]);
    app(Settings::class)->set('auth.providers.google.enabled', true);

    $user = hardeningUser();
    hardeningEnrolTotp($user);

    $sso = new SocialiteUser;
    $sso->id = 'g-1';
    $sso->name = 'Ada';
    $sso->email = 'ada@example.com';
    $sso->token = 'tok';
    $sso->setRaw(['sub' => 'g-1', 'email' => 'ada@example.com', 'email_verified' => true]);
    Socialite::shouldReceive('driver->user')->andReturn($sso);

    $this->get('/login/sso/google/callback')->assertRedirect('/login/two-factor');

    expect(auth('pb')->check())->toBeFalse()
        ->and(session(TwoFactorController::PENDING_KEY))->toBe($user->getKey());
})->skip(fn (): bool => ! class_exists(Socialite::class), 'laravel/socialite not installed');

it('L4: the PHP lint endpoint leaves no temp files behind', function (): void {
    $this->actingAs(synapseAdmin());
    $before = glob(sys_get_temp_dir().'/pb_lint_*') ?: [];

    $this->postJson('/ai-page-builder/lint/php', ['code' => 'echo 1;'])->assertOk();
    $this->postJson('/ai-page-builder/lint/php', ['code' => 'echo ;'])->assertOk();

    expect(array_diff(glob(sys_get_temp_dir().'/pb_lint_*') ?: [], $before))->toBe([]);
});
