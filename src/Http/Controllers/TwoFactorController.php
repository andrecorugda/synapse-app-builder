<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Auth\TwoFactorService;
use Andre\AiPageBuilder\Models\PbUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Two-factor: the post-password login challenge (session-gated by a pending user
 * id, the user is NOT yet authenticated) and the logged-in user's self-service
 * enrolment / disable. The challenge accepts a method code or a recovery code.
 */
class TwoFactorController
{
    public const PENDING_KEY = 'pb_2fa_user';

    public const REMEMBER_KEY = 'pb_2fa_remember';

    public function __construct(private readonly TwoFactorService $tfa) {}

    /* ---- Login challenge (not yet authenticated) ---- */

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user instanceof PbUser) {
            return redirect($this->loginUrl());
        }

        return view('ai-page-builder::auth.two-factor-challenge', [
            'method' => (string) $user->getAttribute('two_factor_method'),
            'loginPath' => $this->loginPath(),
        ]);
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user instanceof PbUser) {
            return redirect($this->loginUrl());
        }

        $data = $request->validate(['code' => ['required', 'string']]);

        if (! $this->tfa->challenge($user, $data['code'])) {
            return back()->withErrors(['code' => 'That code is incorrect or has expired.']);
        }

        $remember = (bool) $request->session()->pull(self::REMEMBER_KEY, false);
        $request->session()->forget(self::PENDING_KEY);

        Auth::guard($this->guard())->login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended((string) config('ai-page-builder.auth.redirect_after_login', '/'));
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user instanceof PbUser && $user->getAttribute('two_factor_method') === TwoFactorService::METHOD_EMAIL) {
            $this->tfa->sendEmailCode($user);
        }

        return back()->with('status', 'A new code has been sent.');
    }

    /* ---- Self-service setup (authenticated pb user) ---- */

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $this->currentUser();
        if (! $user instanceof PbUser) {
            return redirect($this->loginUrl());
        }

        return view('ai-page-builder::auth.two-factor-setup', [
            'user' => $user,
            'enabled' => $this->tfa->isEnabled($user),
            'pendingMethod' => $this->tfa->isEnabled($user) ? null : $user->getAttribute('two_factor_method'),
            'secret' => $this->tfa->isEnabled($user) ? null : $user->getAttribute('two_factor_secret'),
            'methods' => $this->tfa->allowedMethods(),
            'loginPath' => $this->loginPath(),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $user = $this->currentUser();
        if (! $user instanceof PbUser) {
            return redirect($this->loginUrl());
        }

        $data = $request->validate(['method' => ['required', 'in:totp,email']]);
        $method = $data['method'];

        if (! in_array($method, $this->tfa->allowedMethods(), true)) {
            return back()->withErrors(['method' => 'That two-factor method is not available.']);
        }

        if ($method === TwoFactorService::METHOD_TOTP) {
            $this->tfa->beginTotp($user);
        } else {
            $this->tfa->beginEmail($user);
        }

        return redirect($this->setupUrl());
    }

    public function confirm(Request $request): View|RedirectResponse
    {
        $user = $this->currentUser();
        if (! $user instanceof PbUser) {
            return redirect($this->loginUrl());
        }

        $data = $request->validate(['code' => ['required', 'string']]);
        $codes = $this->tfa->confirm($user, $data['code']);

        if ($codes === null) {
            return back()->withErrors(['code' => 'That code is incorrect or has expired.']);
        }

        return view('ai-page-builder::auth.two-factor-recovery', [
            'codes' => $codes,
            'loginPath' => $this->loginPath(),
        ]);
    }

    public function disable(Request $request): RedirectResponse
    {
        $user = $this->currentUser();
        if (! $user instanceof PbUser) {
            return redirect($this->loginUrl());
        }

        $this->tfa->disable($user);

        return redirect($this->setupUrl())->with('status', 'Two-factor authentication has been turned off.');
    }

    private function pendingUser(Request $request): ?PbUser
    {
        $id = $request->session()->get(self::PENDING_KEY);
        if ($id === null) {
            return null;
        }

        /** @var class-string<PbUser> $userClass */
        $userClass = config('ai-page-builder.models.user', PbUser::class);
        $user = $userClass::query()->find($id);

        return $user instanceof PbUser ? $user : null;
    }

    private function currentUser(): ?PbUser
    {
        $user = Auth::guard($this->guard())->user();

        return $user instanceof PbUser ? $user : null;
    }

    private function guard(): string
    {
        return (string) config('ai-page-builder.auth.guard', 'pb');
    }

    private function loginPath(): string
    {
        return trim((string) config('ai-page-builder.auth.login_path', 'login'), '/');
    }

    private function loginUrl(): string
    {
        return '/'.$this->loginPath();
    }

    private function setupUrl(): string
    {
        return '/'.$this->loginPath().'/two-factor/setup';
    }
}
