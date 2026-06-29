<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Auth\AuthSettings;
use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Services\PageBuilderMailer;
use Andre\AiPageBuilder\Support\Schema as PbSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Forgot / reset password for the `pb` guard, self-contained (its own
 * page_builder_password_resets table — independent of the host app's broker).
 * Tokens are stored HASHED; the plaintext lives only in the emailed link.
 * "Forgot" always returns the same message (no email-enumeration oracle) and
 * only emails active accounts. Reset rotates the password and burns the token.
 */
class PasswordResetController
{
    public function showForgot(): View|RedirectResponse
    {
        if (auth((string) config('ai-page-builder.auth.guard', 'pb'))->check()) {
            return redirect()->intended((string) config('ai-page-builder.auth.redirect_after_login', '/'));
        }

        if (! app(AuthSettings::class)->passwordLoginEnabled()) {
            return redirect($this->loginUrl());
        }

        return view('ai-page-builder::auth.forgot-password', ['loginPath' => $this->loginPath()]);
    }

    public function sendReset(Request $request): RedirectResponse
    {
        if (! app(AuthSettings::class)->passwordLoginEnabled()) {
            return redirect($this->loginUrl());
        }

        $data = $request->validate(['email' => ['required', 'email']]);
        $generic = 'If that email matches an account, a password reset link is on its way.';

        /** @var class-string<PbUser> $userClass */
        $userClass = config('ai-page-builder.models.user', PbUser::class);
        $user = $userClass::query()->where('email', $data['email'])->first();

        if ($user instanceof PbUser && $user->canLogin()) {
            $token = Str::random(64);

            $this->table()->updateOrInsert(
                ['email' => $user->getAttribute('email')],
                ['token' => Hash::make($token), 'created_at' => now()],
            );

            $link = url($this->loginPath().'/reset')
                .'?token='.$token.'&email='.urlencode((string) $user->getAttribute('email'));

            $this->mail((string) $user->getAttribute('email'), (string) $user->getAttribute('name'), $link);
        }

        return back()->with('status', $generic);
    }

    public function showReset(Request $request): View|RedirectResponse
    {
        $token = (string) $request->query('token', '');
        $email = (string) $request->query('email', '');

        // Pre-validate so a used / expired link sends the user to request a fresh
        // one, instead of showing a form that's guaranteed to fail on submit.
        if (! $this->tokenIsValid($email, $token)) {
            return redirect('/'.$this->loginPath().'/forgot')
                ->withErrors(['email' => 'This password reset link is invalid or has expired. Please request a new one.']);
        }

        return view('ai-page-builder::auth.reset-password', [
            'token' => $token,
            'email' => $email,
            'loginPath' => $this->loginPath(),
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $invalid = redirect('/'.$this->loginPath().'/forgot')
            ->withErrors(['email' => 'This password reset link is invalid or has expired. Please request a new one.']);

        if (! $this->tokenIsValid($data['email'], $data['token'])) {
            return $invalid;
        }

        /** @var class-string<PbUser> $userClass */
        $userClass = config('ai-page-builder.models.user', PbUser::class);
        $user = $userClass::query()->where('email', $data['email'])->first();
        if (! $user instanceof PbUser) {
            return $invalid;
        }

        $user->setAttribute('password', $data['password']); // hashed by cast
        if ($user->getAttribute('email_verified_at') === null) {
            $user->setAttribute('email_verified_at', now());
        }
        $user->save();

        $this->table()->where('email', $data['email'])->delete();

        return redirect($this->loginUrl())
            ->with('status', 'Your password has been reset — please sign in.');
    }

    /** A reset token is valid when it matches the stored hash and is not expired. */
    private function tokenIsValid(string $email, string $token): bool
    {
        if ($email === '' || $token === '') {
            return false;
        }

        $row = $this->table()->where('email', $email)->first();
        if ($row === null || ! is_string($row->token ?? null) || ! Hash::check($token, $row->token)) {
            return false;
        }

        $ttl = app(AuthSettings::class)->resetTokenTtl();
        $createdAt = isset($row->created_at) ? Carbon::parse((string) $row->created_at) : null;

        return $createdAt !== null && $createdAt->gte(now()->subSeconds($ttl));
    }

    private function mail(string $email, string $name, string $link): void
    {
        try {
            $mailer = app(PageBuilderMailer::class);
            if (! $mailer->configured()) {
                return;
            }

            $safeLink = e($link);
            $html = '<p>Hi '.e($name).',</p>'
                .'<p>We received a request to reset your password. Click the link below to choose a new one:</p>'
                .'<p><a href="'.$safeLink.'">'.$safeLink.'</a></p>'
                .'<p>If you didn\'t request this, you can safely ignore this email.</p>';

            $mailer->send($email, 'Reset your password', $html);
        } catch (\Throwable) {
            // Best-effort: never reveal a delivery failure to the requester.
        }
    }

    private function table(): Builder
    {
        return DB::connection(PbSchema::connection())->table(PbSchema::table('password_resets'));
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
