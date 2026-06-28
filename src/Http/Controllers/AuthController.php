<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

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
    public function show(): View
    {
        return view('ai-page-builder::auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
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
