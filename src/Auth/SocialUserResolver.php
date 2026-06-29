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
 * Then find-or-create by (provider, provider_id), linking an existing account
 * with the same email. New accounts follow the onboarding policy (approval →
 * status=pending) and get the default role; SSO users have no local password.
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

        // 2) Existing local account with the same (provider-verified) email — link it.
        if (! $user instanceof PbUser) {
            $user = $userClass::query()->where('email', $email)->first();
            if ($user instanceof PbUser) {
                $user->setAttribute('provider', $provider);
                $user->setAttribute('provider_id', $providerId);
                if ($user->getAttribute('email_verified_at') === null) {
                    $user->setAttribute('email_verified_at', now());
                }
                $user->save();
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
