<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Auth\AuthSettings;
use Andre\AiPageBuilder\Auth\SocialProviders;
use Andre\AiPageBuilder\Auth\TwoFactorService;
use Andre\AiPageBuilder\Http\Controllers\TwoFactorController as TwoFactor;
use Andre\AiPageBuilder\Models\PbUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * The built app's own login / logout, backed by the package's `pb` guard (not
 * the host app's auth). The login page is a standalone, brandable view — it is
 * the door an end-user uses to enter a gated page or internal tool.
 */
class AuthController
{
    public function show(): View|RedirectResponse
    {
        // Already signed in? The login page is for guests — send them home.
        if (Auth::guard($this->guard())->check()) {
            return redirect()->intended((string) config('ai-page-builder.auth.redirect_after_login', '/'));
        }

        $auth = app(AuthSettings::class);

        // Drive which doors the login page shows (password form, register link,
        // forgot link, SSO buttons added in Phase 3).
        return view('ai-page-builder::auth.login', [
            'passwordLogin' => $auth->passwordLoginEnabled(),
            'registrationAllowed' => $auth->publicRegistrationAllowed(),
            'loginPath' => trim((string) config('ai-page-builder.auth.login_path', 'login'), '/'),
            // SSO buttons to render (empty unless Socialite is installed and a
            // provider is enabled + credentialed).
            'ssoProviders' => app(SocialProviders::class)->usableList(),
        ]);
    }

    public function login(Request $request): RedirectResponse
    {
        // Honour the password-login toggle: SSO-only apps reject password sign-in.
        if (! app(AuthSettings::class)->passwordLoginEnabled()) {
            return back()
                ->withErrors(['email' => 'Password sign-in is disabled for this app.'])
                ->onlyInput('email');
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // is_active is folded into the lookup so deactivated users can't log in.
        $ok = Auth::guard($this->guard())->attempt(
            ['email' => $credentials['email'], 'password' => $credentials['password'], 'is_active' => true],
            $request->boolean('remember'),
        );

        if (! $ok) {
            return back()
                ->withErrors(['email' => 'Those credentials do not match our records.'])
                ->onlyInput('email');
        }

        // Account-status gate (only revealed to someone who passed the password
        // check, so it isn't an email-enumeration oracle): pending awaits admin
        // approval, suspended is blocked.
        $user = Auth::guard($this->guard())->user();
        if ($user instanceof PbUser && ! $user->canLogin()) {
            Auth::guard($this->guard())->logout();

            return back()
                ->withErrors(['email' => $user->getAttribute('status') === PbUser::STATUS_PENDING
                    ? 'Your account is awaiting approval.'
                    : 'Your account has been suspended.'])
                ->onlyInput('email');
        }

        // Two-factor gate: credentials are correct, but hold off on a full
        // session until the second factor is verified. Stash the pending user +
        // remember choice and send them to the challenge (emailing a code first
        // for the email method).
        $tfa = app(TwoFactorService::class);
        if ($user instanceof PbUser && $tfa->policyEnabled() && $tfa->isEnabled($user)) {
            $remember = $request->boolean('remember');
            Auth::guard($this->guard())->logout();

            $request->session()->put(TwoFactor::PENDING_KEY, $user->getKey());
            $request->session()->put(TwoFactor::REMEMBER_KEY, $remember);

            if ($user->getAttribute('two_factor_method') === TwoFactorService::METHOD_EMAIL) {
                $tfa->sendEmailCode($user);
            }

            return redirect('/'.trim((string) config('ai-page-builder.auth.login_path', 'login'), '/').'/two-factor');
        }

        $request->session()->regenerate();

        return redirect()->intended((string) config('ai-page-builder.auth.redirect_after_login', '/'));
    }

    /**
     * The current end-user as JSON, for the published page's runtime to drive
     * component visibility (data-pb-auth / data-pb-roles). { user: null } when
     * signed out.
     */
    public function me(): JsonResponse
    {
        /** @var PbUser|null $user */
        $user = Auth::guard($this->guard())->user();

        return response()->json([
            'user' => $user === null ? null : [
                'id' => $user->getKey(),
                'name' => $user->name,
                'role' => $user->role?->slug,
                'is_admin' => $user->isAdmin(),
            ],
        ]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard($this->guard())->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/'.trim((string) config('ai-page-builder.auth.login_path', 'login'), '/'));
    }

    private function guard(): string
    {
        return (string) config('ai-page-builder.auth.guard', 'pb');
    }
}
