<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Auth;

use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * Enforces SSO sign-in policy and maps a provider identity to a local PbUser.
 * Restrictions are enforced SERVER-SIDE here (not just hinted at the provider):
 *   - Google / Microsoft → the verified email must be in the allowed domains.
 *   - GitHub             → the user must belong to an allowed org (memberships
 *                          are fetched by the controller and passed in).
 * Then find-or-create by (provider, provider_id). An existing local account
 * with the same email is linked ONLY when the provider asserts the email is
 * verified (otherwise the sign-in is rejected, to prevent account takeover via
 * an unverified provider identity). New accounts follow the onboarding policy
 * (approval → status=pending) and get the default role; SSO users have no local
 * password.
 */
class SocialUserResolver
{
    public function __construct(
        private readonly SocialProviders $providers,
        private readonly AuthSettings $auth,
    ) {}

    /**
     * @param  array<int,string>  $githubOrgs  the user's org logins (lower-cased), for GitHub
     *
     * @throws SocialAuthException when policy rejects the sign-in
     */
    public function resolve(string $provider, SocialiteUser $ssoUser, array $githubOrgs = []): PbUser
    {
        $email = strtolower(trim((string) $ssoUser->getEmail()));
        if ($email === '') {
            throw new SocialAuthException('Your '.$this->providers->label($provider).' account did not share an email address.');
        }

        $this->assertDomainAllowed($provider, $email);
        $this->assertOrgAllowed($provider, $githubOrgs);

        /** @var class-string<PbUser> $userClass */
        $userClass = config('ai-page-builder.models.user', PbUser::class);
        $providerId = (string) $ssoUser->getId();

        // 1) Existing SSO link.
        $user = $userClass::query()
            ->where('provider', $provider)
            ->where('provider_id', $providerId)
            ->first();

        // 2) Existing local account with the same email — link it, but ONLY when
        // the provider asserts the email is verified. Linking on a bare email
        // match would let an attacker who controls an unverified provider
        // identity take over an existing local account, so we refuse to merge
        // (and never stamp email_verified_at) for unverified provider emails.
        if (! $user instanceof PbUser) {
            $existing = $userClass::query()->where('email', $email)->first();
            if ($existing instanceof PbUser) {
                if (! $this->providerVerifiedEmail($provider, $ssoUser)) {
                    throw new SocialAuthException(
                        'Your '.$this->providers->label($provider).' account has not verified this email address. '
                        .'Sign in with your existing method to link '.$this->providers->label($provider).'.'
                    );
                }

                $existing->setAttribute('provider', $provider);
                $existing->setAttribute('provider_id', $providerId);
                if ($existing->getAttribute('email_verified_at') === null) {
                    $existing->setAttribute('email_verified_at', now());
                }
                $existing->save();
                $user = $existing;
            }
        }

        // 3) Brand-new account, per the onboarding policy.
        if (! $user instanceof PbUser) {
            $user = $userClass::query()->create([
                'name' => ((string) $ssoUser->getName()) ?: ((string) $ssoUser->getNickname() ?: $email),
                'email' => $email,
                'provider' => $provider,
                'provider_id' => $providerId,
                'password' => null,
                'is_active' => true,
                'status' => $this->auth->registrationNeedsApproval()
                    ? PbUser::STATUS_PENDING
                    : PbUser::STATUS_ACTIVE,
                'email_verified_at' => now(),
                'role_id' => $this->resolveRoleId($this->auth->defaultRole()),
            ]);
        }

        return $user;
    }

    /**
     * Whether the provider asserts the signing-in email is verified, read from
     * the Socialite user's raw claims:
     *   - Google / Microsoft → `email_verified` / `verified` (bool or "true"/"1").
     *   - GitHub (user:email scope) → the primary email's `verified` flag, which
     *     Socialite surfaces under the raw `email_verified` key.
     * Absent/falsey claims are treated as NOT verified (fail closed).
     */
    private function providerVerifiedEmail(string $provider, SocialiteUser $ssoUser): bool
    {
        $raw = method_exists($ssoUser, 'getRaw') ? $ssoUser->getRaw() : [];
        if (! is_array($raw)) {
            return false;
        }

        foreach (['email_verified', 'verified'] as $key) {
            if (array_key_exists($key, $raw) && $this->isTruthyClaim($raw[$key])) {
                return true;
            }
        }

        return false;
    }

    /** Normalize a provider claim that may arrive as bool, int, or string. */
    private function isTruthyClaim(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private function assertDomainAllowed(string $provider, string $email): void
    {
        $domains = $this->providers->allowedDomains($provider);
        if ($domains === []) {
            return;
        }

        $at = strrchr($email, '@');
        $domain = $at === false ? '' : substr($at, 1);

        if (! in_array($domain, $domains, true)) {
            throw new SocialAuthException('That email domain is not allowed to sign in to this app.');
        }
    }

    /**
     * @param  array<int,string>  $githubOrgs
     */
    private function assertOrgAllowed(string $provider, array $githubOrgs): void
    {
        if ($provider !== 'github') {
            return;
        }

        $allowed = $this->providers->allowedOrgs($provider);
        if ($allowed === []) {
            return;
        }

        $member = array_intersect(
            array_map('strtolower', $githubOrgs),
            $allowed,
        );

        if ($member === []) {
            throw new SocialAuthException('Your GitHub account is not a member of an allowed organization.');
        }
    }

    private function resolveRoleId(?string $slug): ?int
    {
        if ($slug === null) {
            return null;
        }

        /** @var class-string<PbRole> $roleClass */
        $roleClass = config('ai-page-builder.models.role', PbRole::class);
        $id = $roleClass::query()->where('slug', $slug)->value('id');

        return $id === null ? null : (int) $id;
    }
}
