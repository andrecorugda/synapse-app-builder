<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Auth\AuthSettings;
use Andre\AiPageBuilder\Models\PbRole;
use Andre\AiPageBuilder\Models\PbUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Self-registration for the built app's end-users (the `pb` guard). Gated by the
 * admin's onboarding policy (AuthSettings): only reachable when registration is
 * enabled and not invite-only. In "approval" mode a new account is created
 * `pending` and cannot log in until an admin approves it; in "open" mode it is
 * active and signed in immediately. An optional email-domain allow-list applies.
 */
class RegistrationController
{
    public function show(): View|RedirectResponse
    {
        if (! app(AuthSettings::class)->publicRegistrationAllowed()) {
            return redirect($this->loginUrl());
        }

        return view('ai-page-builder::auth.register', ['loginPath' => $this->loginPath()]);
    }

    public function register(Request $request): RedirectResponse
    {
        $auth = app(AuthSettings::class);

        if (! $auth->publicRegistrationAllowed()) {
            return redirect($this->loginUrl());
        }

        /** @var class-string<PbUser> $userClass */
        $userClass = config('ai-page-builder.models.user', PbUser::class);
        $model = new $userClass;
        $conn = $model->getConnectionName();
        $uniqueTable = ($conn ? $conn.'.' : '').$model->getTable();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200', Rule::unique($uniqueTable, 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (! $auth->emailDomainAllowed($data['email'])) {
            return back()
                ->withErrors(['email' => 'Registration is not open to that email domain.'])
                ->onlyInput('name', 'email');
        }

        $needsApproval = $auth->registrationNeedsApproval();

        /** @var PbUser $user */
        $user = $userClass::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'], // hashed by the model cast
            'is_active' => true,
            'status' => $needsApproval ? PbUser::STATUS_PENDING : PbUser::STATUS_ACTIVE,
            'role_id' => $this->resolveRoleId($auth->defaultRole()),
        ]);

        if ($needsApproval) {
            return redirect($this->loginUrl())
                ->with('status', 'Thanks for registering — your account is awaiting approval.');
        }

        Auth::guard($this->guard())->login($user);
        $request->session()->regenerate();

        return redirect()->intended((string) config('ai-page-builder.auth.redirect_after_login', '/'));
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
