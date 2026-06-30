<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Auth\TwoFactorService;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

/**
 * An active end-user of the built app (pb guard) with a known password.
 */
function tfaUser(array $attrs = []): PbUser
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
function tfaSetting(string $key, mixed $value): void
{
    app(Settings::class)->set($key, $value);
}

function tfaService(): TwoFactorService
{
    return app(TwoFactorService::class);
}

/** The current valid TOTP code for a secret. */
function currentOtp(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

// --- 1. TOTP enrolment + confirm --------------------------------------------

it('enrols and confirms TOTP, returning recovery codes and enabling 2FA', function (): void {
    $user = tfaUser();

    $begin = tfaService()->beginTotp($user);
    expect($begin)->toHaveKeys(['secret', 'otpauth'])
        ->and($begin['secret'])->toBeString()->not->toBe('')
        ->and(tfaService()->isEnabled($user))->toBeFalse();

    $codes = tfaService()->confirm($user, currentOtp($begin['secret']));

    expect($codes)->toBeArray()->toHaveCount(8)
        ->and($user->refresh()->getAttribute('two_factor_confirmed_at'))->not->toBeNull()
        ->and(tfaService()->isEnabled($user))->toBeTrue();
});

it('does not enable TOTP when confirmed with a wrong code', function (): void {
    $user = tfaUser();
    tfaService()->beginTotp($user);

    $result = tfaService()->confirm($user, '000000');

    expect($result)->toBeNull()
        ->and($user->refresh()->getAttribute('two_factor_confirmed_at'))->toBeNull()
        ->and(tfaService()->isEnabled($user))->toBeFalse();
});

// --- 2. Email method --------------------------------------------------------

it('verifies an email OTP from the cache and consumes it', function (): void {
    $user = tfaUser(['two_factor_method' => TwoFactorService::METHOD_EMAIL]);

    // The plaintext code is only emailed, so seed a known hashed code directly.
    Cache::put('pb_2fa_code:'.$user->getKey(), Hash::make('123456'), now()->addMinutes(10));

    expect(tfaService()->verifyCode($user, '999999'))->toBeFalse()
        ->and(tfaService()->verifyCode($user, '123456'))->toBeTrue()
        // Single-use: the cached code is forgotten after a successful match.
        ->and(tfaService()->verifyCode($user, '123456'))->toBeFalse();
});

it('caches a hashed email code on send (never the plaintext)', function (): void {
    $user = tfaUser(['two_factor_method' => TwoFactorService::METHOD_EMAIL]);

    tfaService()->sendEmailCode($user);

    $stored = Cache::get('pb_2fa_code:'.$user->getKey());
    expect($stored)->toBeString()->not->toBe('')
        // It's a hash, not the 6-digit code.
        ->and($stored)->not->toMatch('/^\d{6}$/');
});

// --- 3. Recovery codes ------------------------------------------------------

it('consumes a recovery code once via verifyRecoveryCode', function (): void {
    $user = tfaUser();

    $begin = tfaService()->beginTotp($user);
    $codes = tfaService()->confirm($user, currentOtp($begin['secret']));
    $code = $codes[0];

    expect(tfaService()->verifyRecoveryCode($user, $code))->toBeTrue()
        // Same code is spent and rejected the second time.
        ->and(tfaService()->verifyRecoveryCode($user, $code))->toBeFalse();
});

it('stores recovery codes hashed, never in plaintext', function (): void {
    $user = tfaUser();

    $begin = tfaService()->beginTotp($user);
    $codes = tfaService()->confirm($user, currentOtp($begin['secret']));

    /** @var array<int,string> $stored */
    $stored = (array) $user->refresh()->getAttribute('two_factor_recovery_codes');

    expect($stored)->toHaveCount(8);
    foreach ($codes as $plain) {
        // The plaintext code never appears verbatim in the stored column...
        expect($stored)->not->toContain($plain);
    }
    foreach ($stored as $hash) {
        // ...and every stored value is a bcrypt hash that matches via Hash::check.
        expect($hash)->toStartWith('$2y$');
    }
    // Sanity: a stored hash verifies against its originating plaintext.
    expect(Hash::check($codes[0], $stored[0]))->toBeTrue();
});

it('consuming one recovery code leaves the others usable', function (): void {
    $user = tfaUser();

    $begin = tfaService()->beginTotp($user);
    $codes = tfaService()->confirm($user, currentOtp($begin['secret']));

    expect(tfaService()->verifyRecoveryCode($user, $codes[0]))->toBeTrue()
        ->and(tfaService()->verifyRecoveryCode($user, $codes[0]))->toBeFalse()
        // A different, still-unspent code remains valid.
        ->and(tfaService()->verifyRecoveryCode($user, $codes[1]))->toBeTrue();
});

it('accepts a recovery code at the challenge', function (): void {
    $user = tfaUser();

    $begin = tfaService()->beginTotp($user);
    $codes = tfaService()->confirm($user, currentOtp($begin['secret']));

    expect(tfaService()->challenge($user, $codes[1]))->toBeTrue()
        ->and(tfaService()->challenge($user, $codes[1]))->toBeFalse();
});

// --- 4. Disable -------------------------------------------------------------

it('disable clears all two-factor fields', function (): void {
    $user = tfaUser();

    $begin = tfaService()->beginTotp($user);
    tfaService()->confirm($user, currentOtp($begin['secret']));
    expect(tfaService()->isEnabled($user))->toBeTrue();

    tfaService()->disable($user);

    $user->refresh();
    expect(tfaService()->isEnabled($user))->toBeFalse()
        ->and($user->getAttribute('two_factor_method'))->toBeNull()
        ->and($user->getAttribute('two_factor_secret'))->toBeNull()
        ->and($user->getAttribute('two_factor_recovery_codes'))->toBeNull()
        ->and($user->getAttribute('two_factor_confirmed_at'))->toBeNull();
});

// --- 5. allowedMethods ------------------------------------------------------

it('offers both TOTP and email when the package is available', function (): void {
    expect(tfaService()->totpAvailable())->toBeTrue()
        ->and(tfaService()->allowedMethods())
        ->toEqual([TwoFactorService::METHOD_TOTP, TwoFactorService::METHOD_EMAIL]);
});

it('restricts allowedMethods when settings limit them to email', function (): void {
    tfaSetting('auth.two_factor.methods', ['email']);

    expect(tfaService()->allowedMethods())->toEqual([TwoFactorService::METHOD_EMAIL]);
});

// --- 6. Login challenge flow (integration) ----------------------------------

it('holds a TOTP user at the challenge then signs them in with a valid code', function (): void {
    $user = tfaUser();
    $begin = tfaService()->beginTotp($user);
    tfaService()->confirm($user, currentOtp($begin['secret']));

    // Correct password alone does NOT authenticate — it routes to the challenge.
    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect('/login/two-factor');
    expect(auth('pb')->check())->toBeFalse();

    // A wrong code keeps them out.
    $this->from('/login/two-factor')
        ->post('/login/two-factor', ['code' => '000000'])
        ->assertSessionHasErrors('code');
    expect(auth('pb')->check())->toBeFalse();

    // The current OTP completes the sign-in.
    $this->post('/login/two-factor', ['code' => currentOtp($begin['secret'])])
        ->assertRedirect();
    expect(auth('pb')->check())->toBeTrue();
});

it('logs a user without 2FA straight in (regression)', function (): void {
    tfaUser();

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect();

    expect(auth('pb')->check())->toBeTrue();
});

// --- 7. Policy switch -------------------------------------------------------

it('skips the challenge when the 2FA policy is disabled', function (): void {
    $user = tfaUser();
    $begin = tfaService()->beginTotp($user);
    tfaService()->confirm($user, currentOtp($begin['secret']));

    // Policy off: the enrolled user logs straight in, no challenge redirect.
    tfaSetting('auth.two_factor.enabled', false);

    $this->post('/login', ['email' => 'ada@example.com', 'password' => 'secret-pass'])
        ->assertRedirect();
    expect(auth('pb')->check())->toBeTrue();
});
