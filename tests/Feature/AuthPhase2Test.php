<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Auth\AuthSettings;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Settings;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create an end-user of the built app (pb guard). Defaults to an active account
 * with a known password.
 */
function phase2User(array $attrs = []): PbUser
{
    return PbUser::query()->create(array_merge([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'secret-pass',
        'is_active' => true,
        'status' => PbUser::STATUS_ACTIVE,
    ], $attrs));
}

/** Flip a runtime auth-policy key through the Settings service. */
function authSetting(string $key, mixed $value): void
{
    app(Settings::class)->set($key, $value);
}

/** The self-contained reset-token table the controller writes to. */
function resetTable(): Builder
{
    return DB::connection(PbSchema::connection())->table(PbSchema::table('password_resets'));
}

// --- 1. password-login toggle ----------------------------------------------

it('rejects POST /login when password login is disabled', function (): void {
    phase2User();
    authSetting('auth.password_login', false);

    $this->from('/login')
        ->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    expect(auth('pb')->check())->toBeFalse();
});

// --- 2. account-status gate -------------------------------------------------

it('refuses login for a pending account', function (): void {
    phase2User(['status' => PbUser::STATUS_PENDING]);

    $this->from('/login')
        ->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    expect(auth('pb')->check())->toBeFalse();
});

it('refuses login for a suspended account', function (): void {
    phase2User(['status' => PbUser::STATUS_SUSPENDED]);

    $this->from('/login')
        ->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    expect(auth('pb')->check())->toBeFalse();
});

it('logs in an active account', function (): void {
    phase2User();

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect();

    expect(auth('pb')->check())->toBeTrue();
});

// --- 3. registration: open mode ---------------------------------------------

it('open registration creates an active user and logs them in', function (): void {
    authSetting('auth.registration_enabled', true);
    authSetting('auth.registration_mode', AuthSettings::MODE_OPEN);

    $this->post('/login/register', [
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'hopper-pass',
        'password_confirmation' => 'hopper-pass',
    ])->assertRedirect();

    $user = PbUser::query()->where('email', 'grace@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->getAttribute('status'))->toBe(PbUser::STATUS_ACTIVE)
        ->and(auth('pb')->check())->toBeTrue();
});

// --- 4. registration: approval mode -----------------------------------------

it('approval registration creates a pending user that cannot log in until activated', function (): void {
    authSetting('auth.registration_enabled', true);
    authSetting('auth.registration_mode', AuthSettings::MODE_APPROVAL);

    $this->post('/login/register', [
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'hopper-pass',
        'password_confirmation' => 'hopper-pass',
    ])->assertRedirect('/login');

    $user = PbUser::query()->where('email', 'grace@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->getAttribute('status'))->toBe(PbUser::STATUS_PENDING)
        ->and(auth('pb')->check())->toBeFalse();

    // Pending user is barred at the login door.
    $this->from('/login')
        ->post('/login', ['email' => 'grace@example.com', 'password' => 'hopper-pass'])
        ->assertSessionHasErrors('email');
    expect(auth('pb')->check())->toBeFalse();

    // Once an admin activates the account, the same credentials work.
    $user->forceFill(['status' => PbUser::STATUS_ACTIVE])->save();

    $this->post('/login', ['email' => 'grace@example.com', 'password' => 'hopper-pass'])
        ->assertRedirect();
    expect(auth('pb')->check())->toBeTrue();
});

// --- 5. registration: invite-only / disabled --------------------------------

it('invite-only registration is closed to the public form', function (): void {
    authSetting('auth.registration_enabled', true);
    authSetting('auth.registration_mode', AuthSettings::MODE_INVITE_ONLY);

    $this->get('/login/register')->assertRedirect('/login');

    $this->post('/login/register', [
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'hopper-pass',
        'password_confirmation' => 'hopper-pass',
    ])->assertRedirect('/login');

    expect(PbUser::query()->where('email', 'grace@example.com')->exists())->toBeFalse();
});

it('disabled registration does not create a user', function (): void {
    authSetting('auth.registration_enabled', false);

    $this->get('/login/register')->assertRedirect('/login');

    $this->post('/login/register', [
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'hopper-pass',
        'password_confirmation' => 'hopper-pass',
    ])->assertRedirect('/login');

    expect(PbUser::query()->where('email', 'grace@example.com')->exists())->toBeFalse();
});

// --- 6. email-domain allow-list ---------------------------------------------

it('enforces the email-domain allow-list on registration', function (): void {
    authSetting('auth.registration_enabled', true);
    authSetting('auth.registration_mode', AuthSettings::MODE_OPEN);
    authSetting('auth.allowed_email_domains', ['acme.com']);

    // Off-list domain is rejected and no user is created.
    $this->from('/login/register')
        ->post('/login/register', [
            'name' => 'Outsider',
            'email' => 'x@other.com',
            'password' => 'hopper-pass',
            'password_confirmation' => 'hopper-pass',
        ])
        ->assertRedirect('/login/register')
        ->assertSessionHasErrors('email');
    expect(PbUser::query()->where('email', 'x@other.com')->exists())->toBeFalse();

    // Allowed domain succeeds.
    $this->post('/login/register', [
        'name' => 'Insider',
        'email' => 'x@acme.com',
        'password' => 'hopper-pass',
        'password_confirmation' => 'hopper-pass',
    ])->assertRedirect();
    expect(PbUser::query()->where('email', 'x@acme.com')->exists())->toBeTrue();
});

// --- 7. forgot password -----------------------------------------------------

it('forgot password issues a token for an active account', function (): void {
    phase2User();

    $this->post('/login/forgot', ['email' => 'ada@example.com'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(resetTable()->where('email', 'ada@example.com')->exists())->toBeTrue();
});

it('forgot password gives the same generic response for an unknown email and creates no token', function (): void {
    $this->post('/login/forgot', ['email' => 'nobody@example.com'])
        ->assertRedirect()
        ->assertSessionHas('status');

    expect(resetTable()->where('email', 'nobody@example.com')->exists())->toBeFalse()
        ->and(resetTable()->count())->toBe(0);
});

// --- 8. reset password ------------------------------------------------------

it('resets the password with a valid token and burns the token', function (): void {
    $user = phase2User();

    $token = Str::random(64);
    resetTable()->insert([
        'email' => 'ada@example.com',
        'token' => Hash::make($token),
        'created_at' => now(),
    ]);

    $this->post('/login/reset', [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ])->assertRedirect('/login')->assertSessionHas('status');

    // Token row is gone.
    expect(resetTable()->where('email', 'ada@example.com')->exists())->toBeFalse();

    // Old password fails, new password works.
    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass']);
    expect(auth('pb')->check())->toBeFalse();

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'brand-new-pass'])
        ->assertRedirect();
    expect(auth('pb')->check())->toBeTrue();
});

it('rejects a reset with an invalid token', function (): void {
    phase2User();

    resetTable()->insert([
        'email' => 'ada@example.com',
        'token' => Hash::make(Str::random(64)),
        'created_at' => now(),
    ]);

    $this->from('/login/reset')
        ->post('/login/reset', [
            'token' => 'not-the-real-token',
            'email' => 'ada@example.com',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])
        ->assertSessionHasErrors('email');

    // Original password is unchanged.
    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect();
    expect(auth('pb')->check())->toBeTrue();
});

it('rejects a reset with an expired token', function (): void {
    phase2User();

    $token = Str::random(64);
    $ttl = app(AuthSettings::class)->resetTokenTtl();
    resetTable()->insert([
        'email' => 'ada@example.com',
        'token' => Hash::make($token),
        'created_at' => Carbon::now()->subSeconds($ttl + 60),
    ]);

    $this->from('/login/reset')
        ->post('/login/reset', [
            'token' => $token,
            'email' => 'ada@example.com',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])
        ->assertSessionHasErrors('email');

    // Old password still valid (reset was refused).
    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect();
    expect(auth('pb')->check())->toBeTrue();
});

it('shows the reset form for a valid link but bounces a stale link to forgot', function (): void {
    phase2User();

    $token = Str::random(64);
    resetTable()->insert([
        'email' => 'ada@example.com',
        'token' => Hash::make($token),
        'created_at' => now(),
    ]);

    // Valid link → the reset form renders.
    $this->get('/login/reset?token='.$token.'&email=ada@example.com')->assertOk();

    // Used / wrong token → redirected to request a new link (no doomed form).
    $this->get('/login/reset?token=WRONG&email=ada@example.com')
        ->assertRedirect('/login/forgot')
        ->assertSessionHasErrors('email');
});

it('logs the end-user out via pb-logout', function (): void {
    phase2User();

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass']);
    expect(auth('pb')->check())->toBeTrue();

    $this->post('/pb-logout')->assertRedirect();
    expect(auth('pb')->check())->toBeFalse();
});

it('redirects an already signed-in user away from the guest auth pages', function (): void {
    $this->actingAs(phase2User(), 'pb');

    $this->get('/login')->assertRedirect();
    $this->get('/login/register')->assertRedirect();
    $this->get('/login/forgot')->assertRedirect();
});
