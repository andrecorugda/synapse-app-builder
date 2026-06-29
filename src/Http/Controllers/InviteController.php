<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Auth\InviteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Public accept-invite flow: the invitee follows the emailed link, sets a name +
 * password, and their account is activated with the invited role. Validity is
 * checked against the hashed token via InviteService (invalid/expired → bounced
 * back to login). This is how users join in invite-only onboarding mode.
 */
class InviteController
{
    public function __construct(private readonly InviteService $invites) {}

    public function show(Request $request, string $token): View|RedirectResponse
    {
        $email = (string) $request->query('email', '');

        if ($this->invites->findValid($email, $token) === null) {
            return redirect($this->loginUrl())
                ->withErrors(['email' => 'This invitation is invalid or has expired.']);
        }

        return view('ai-page-builder::auth.accept-invite', [
            'token' => $token,
            'email' => $email,
            'loginPath' => $this->loginPath(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:200'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $invite = $this->invites->findValid($data['email'], $token);
        if ($invite === null) {
            return redirect($this->loginUrl())
                ->withErrors(['email' => 'This invitation is invalid or has expired.']);
        }

        $user = $this->invites->accept($invite, $data['name'], $data['password']);

        Auth::guard($this->guard())->login($user);
        $request->session()->regenerate();

        return redirect()->intended((string) config('ai-page-builder.auth.redirect_after_login', '/'));
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
}
