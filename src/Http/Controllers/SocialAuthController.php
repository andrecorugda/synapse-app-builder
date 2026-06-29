<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Auth\SocialAuthException;
use Andre\AiPageBuilder\Auth\SocialProviders;
use Andre\AiPageBuilder\Auth\SocialUserResolver;
use Andre\AiPageBuilder\Models\PbUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * SSO sign-in (Google / Microsoft / GitHub) for the built app's end-users via
 * the OPTIONAL laravel/socialite package. Every entry point gates on
 * SocialProviders::usable() (valid + enabled + credentialed + Socialite
 * installed), so without configuration or the package these routes degrade to a
 * friendly redirect rather than an error. Provider restrictions (domain / org /
 * tenant) are enforced server-side in SocialUserResolver.
 */
class SocialAuthController
{
    public function __construct(
        private readonly SocialProviders $providers,
        private readonly SocialUserResolver $resolver,
    ) {}

    public function redirect(string $provider): RedirectResponse|SymfonyRedirect
    {
        if (! $this->providers->usable($provider)) {
            return $this->loginWithError('Single sign-on is not available.');
        }

        $this->providers->configureSocialite($provider);

        $driver = Socialite::driver($provider);

        // GitHub org restriction needs read:org + the user's email.
        if ($provider === 'github' && $driver instanceof AbstractProvider) {
            $driver->scopes(['read:org', 'user:email']);
        }

        return $driver->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        if (! $this->providers->usable($provider)) {
            return $this->loginWithError('Single sign-on is not available.');
        }

        $this->providers->configureSocialite($provider);

        try {
            $ssoUser = Socialite::driver($provider)->user();
            $orgs = $provider === 'github' ? $this->githubOrgs($ssoUser->token ?? null) : [];

            $user = $this->resolver->resolve($provider, $ssoUser, $orgs);
        } catch (SocialAuthException $e) {
            return $this->loginWithError($e->getMessage());
        } catch (\Throwable) {
            return $this->loginWithError('We could not complete the '.$this->providers->label($provider).' sign-in. Please try again.');
        }

        if (! $user->canLogin()) {
            return redirect($this->loginUrl())->with('status', $user->getAttribute('status') === PbUser::STATUS_PENDING
                ? 'Thanks for signing in — your account is awaiting approval.'
                : 'Your account has been suspended.');
        }

        Auth::guard($this->guard())->login($user, true);
        request()->session()->regenerate();

        return redirect()->intended((string) config('ai-page-builder.auth.redirect_after_login', '/'));
    }

    /**
     * The user's GitHub org logins (lower-cased), for org-membership checks.
     *
     * @return array<int,string>
     */
    private function githubOrgs(?string $token): array
    {
        if (! is_string($token) || $token === '') {
            return [];
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'ai-page-builder'])
                ->get('https://api.github.com/user/orgs');

            if (! $response->successful()) {
                return [];
            }

            return array_values(array_filter(array_map(
                static fn ($org): string => strtolower((string) ($org['login'] ?? '')),
                (array) $response->json(),
            )));
        } catch (\Throwable) {
            return [];
        }
    }

    private function loginWithError(string $message): RedirectResponse
    {
        return redirect($this->loginUrl())->withErrors(['email' => $message]);
    }

    private function guard(): string
    {
        return (string) config('ai-page-builder.auth.guard', 'pb');
    }

    private function loginUrl(): string
    {
        return '/'.trim((string) config('ai-page-builder.auth.login_path', 'login'), '/');
    }
}
