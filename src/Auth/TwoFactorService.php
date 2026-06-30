<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Auth;

use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\PageBuilderMailer;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor auth for the pb guard: authenticator-app TOTP (optional
 * pragmarx/google2fa) and email one-time codes (no dependency), plus single-use
 * recovery codes. Used for enrolment (begin → confirm), the login challenge, and
 * admin reset. The TOTP secret is stored encrypted on PbUser; recovery codes are
 * stored as bcrypt HASHES (under the encrypted:array cast, so the hashes are also
 * encrypted at rest) and verified with Hash::check — never compared in plaintext.
 * Email codes live hashed in the cache with a short TTL.
 */
class TwoFactorService
{
    public const METHOD_TOTP = 'totp';

    public const METHOD_EMAIL = 'email';

    public function __construct(private readonly Settings $settings) {}

    /** Whether the TOTP method is installable (the optional package is present). */
    public function totpAvailable(): bool
    {
        return class_exists(Google2FA::class);
    }

    /** Admin policy: is 2FA offered at all? */
    public function policyEnabled(): bool
    {
        return (bool) $this->settings->get('auth.two_factor.enabled', config('ai-page-builder.auth.two_factor.enabled', true));
    }

    /**
     * Methods a user may enrol with (config/admin choice minus TOTP if the
     * package is missing).
     *
     * @return array<int,string>
     */
    public function allowedMethods(): array
    {
        $methods = $this->settings->get('auth.two_factor.methods', config('ai-page-builder.auth.two_factor.methods', [self::METHOD_TOTP, self::METHOD_EMAIL]));
        $methods = is_array($methods) ? array_values(array_intersect([self::METHOD_TOTP, self::METHOD_EMAIL], $methods)) : [self::METHOD_EMAIL];

        if (! $this->totpAvailable()) {
            $methods = array_values(array_diff($methods, [self::METHOD_TOTP]));
        }

        return $methods === [] ? [self::METHOD_EMAIL] : $methods;
    }

    public function isEnabled(PbUser $user): bool
    {
        return $user->hasTwoFactorEnabled();
    }

    /**
     * Begin TOTP enrolment: generate + store an (unconfirmed) secret and return
     * the secret + otpauth URI for the authenticator app.
     *
     * @return array{secret:string,otpauth:string}
     */
    public function beginTotp(PbUser $user): array
    {
        $google = new Google2FA;
        $secret = $google->generateSecretKey();

        $user->forceFill([
            'two_factor_method' => self::METHOD_TOTP,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
        ])->save();

        $issuer = (string) (config('app.name') ?: 'App');
        $otpauth = $google->getQRCodeUrl($issuer, (string) $user->getAttribute('email'), $secret);

        return ['secret' => $secret, 'otpauth' => $otpauth];
    }

    /** Begin email-OTP enrolment: set the method and send the first code. */
    public function beginEmail(PbUser $user): void
    {
        $user->forceFill([
            'two_factor_method' => self::METHOD_EMAIL,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->sendEmailCode($user);
    }

    /**
     * Confirm enrolment with a code. On success, activate 2FA and return fresh
     * single-use recovery codes; null on a bad code.
     *
     * @return array<int,string>|null
     */
    public function confirm(PbUser $user, string $code): ?array
    {
        if (! $this->verifyCode($user, $code)) {
            return null;
        }

        $codes = $this->generateRecoveryCodes();

        // Persist only bcrypt HASHES of the codes; the plaintext is returned to
        // the user once here and never stored.
        $user->forceFill([
            'two_factor_recovery_codes' => array_map(
                static fn (string $code): string => Hash::make($code),
                $codes,
            ),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codes;
    }

    public function disable(PbUser $user): void
    {
        $user->forceFill([
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /** Generate, cache (hashed, 10 min), and email a 6-digit code. */
    public function sendEmailCode(PbUser $user): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put($this->cacheKey($user), Hash::make($code), now()->addMinutes(10));

        try {
            $mailer = app(PageBuilderMailer::class);
            if ($mailer->configured()) {
                $mailer->send(
                    (string) $user->getAttribute('email'),
                    'Your sign-in code',
                    '<p>Your one-time sign-in code is:</p><p style="font-size:24px;font-weight:bold;letter-spacing:4px">'.$code.'</p><p>It expires in 10 minutes.</p>',
                );
            }
        } catch (\Throwable) {
            // Best-effort.
        }
    }

    /** Verify a method code (TOTP or email OTP). Does NOT cover recovery codes. */
    public function verifyCode(PbUser $user, string $code): bool
    {
        $code = trim($code);
        $method = $user->getAttribute('two_factor_method');

        if ($method === self::METHOD_TOTP && $this->totpAvailable()) {
            $secret = (string) $user->getAttribute('two_factor_secret');

            return $secret !== '' && (new Google2FA)->verifyKey($secret, $code);
        }

        if ($method === self::METHOD_EMAIL) {
            $stored = Cache::get($this->cacheKey($user));
            if (is_string($stored) && Hash::check($code, $stored)) {
                Cache::forget($this->cacheKey($user));

                return true;
            }
        }

        return false;
    }

    /**
     * Consume a single-use recovery code. Stored codes are bcrypt hashes, so we
     * compare each with Hash::check and, on a match, drop that one hash.
     */
    public function verifyRecoveryCode(PbUser $user, string $code): bool
    {
        $code = trim($code);
        if ($code === '') {
            return false;
        }

        /** @var array<int,string> $hashes */
        $hashes = (array) $user->getAttribute('two_factor_recovery_codes');

        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && Hash::check($code, $hash)) {
                unset($hashes[$index]);

                $user->forceFill([
                    'two_factor_recovery_codes' => array_values($hashes),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /** Login-challenge verification: a method code OR a recovery code. */
    public function challenge(PbUser $user, string $code): bool
    {
        return $this->verifyCode($user, $code) || $this->verifyRecoveryCode($user, $code);
    }

    /** @return array<int,string> */
    private function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(Str::random(5)).'-'.strtoupper(Str::random(5));
        }

        return $codes;
    }

    private function cacheKey(PbUser $user): string
    {
        return 'pb_2fa_code:'.$user->getKey();
    }
}
