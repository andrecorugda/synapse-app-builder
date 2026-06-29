<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Auth;

use Andre\AiPageBuilder\Models\PbUser;
use Andre\AiPageBuilder\Models\PbUserInvite;
use Andre\AiPageBuilder\Services\PageBuilderMailer;
use Illuminate\Support\Str;

/**
 * Creates, emails, and redeems end-user invitations. Tokens are stored hashed
 * (plaintext only in the emailed link). Used by the admin "Invites" resource and
 * the public accept flow, so the rules live in one place.
 */
class InviteService
{
    /**
     * Create an invite + email the accept link. Returns the invite; the plaintext
     * token is only known here (it is not persisted).
     */
    public function createAndSend(string $email, ?int $roleId = null, ?int $invitedBy = null, int $ttlDays = 7): PbUserInvite
    {
        $plain = Str::random(64);

        /** @var class-string<PbUserInvite> $inviteClass */
        $inviteClass = config('ai-page-builder.models.user_invite', PbUserInvite::class);

        /** @var PbUserInvite $invite */
        $invite = $inviteClass::query()->create([
            'email' => strtolower(trim($email)),
            'token' => password_hash($plain, PASSWORD_BCRYPT),
            'role_id' => $roleId,
            'invited_by' => $invitedBy,
            'expires_at' => now()->addDays(max(1, $ttlDays)),
            'accepted_at' => null,
        ]);

        $this->mail($invite, $plain);

        return $invite;
    }

    /** Regenerate the token + expiry on an existing invite and re-email it. */
    public function resend(PbUserInvite $invite, int $ttlDays = 7): PbUserInvite
    {
        $plain = Str::random(64);

        $invite->forceFill([
            'token' => password_hash($plain, PASSWORD_BCRYPT),
            'expires_at' => now()->addDays(max(1, $ttlDays)),
            'accepted_at' => null,
        ])->save();

        $this->mail($invite, $plain);

        return $invite;
    }

    public function acceptUrl(PbUserInvite $invite, string $plainToken): string
    {
        $login = trim((string) config('ai-page-builder.auth.login_path', 'login'), '/');

        return url($login.'/invite/'.$plainToken).'?email='.urlencode((string) $invite->getAttribute('email'));
    }

    /** Find a still-redeemable invite matching the email + plaintext token. */
    public function findValid(string $email, string $plainToken): ?PbUserInvite
    {
        /** @var class-string<PbUserInvite> $inviteClass */
        $inviteClass = config('ai-page-builder.models.user_invite', PbUserInvite::class);

        $invites = $inviteClass::query()
            ->where('email', strtolower(trim($email)))
            ->whereNull('accepted_at')
            ->latest()
            ->get();

        foreach ($invites as $invite) {
            if (! $invite->isExpired() && password_verify($plainToken, (string) $invite->getAttribute('token'))) {
                return $invite;
            }
        }

        return null;
    }

    /**
     * Redeem an invite: create (or activate) the end-user with the invited role
     * and mark the invite accepted. The acting password is hashed by the model.
     */
    public function accept(PbUserInvite $invite, string $name, string $password): PbUser
    {
        /** @var class-string<PbUser> $userClass */
        $userClass = config('ai-page-builder.models.user', PbUser::class);
        $email = (string) $invite->getAttribute('email');

        /** @var PbUser|null $user */
        $user = $userClass::query()->where('email', $email)->first();

        $attributes = [
            'name' => $name,
            'password' => $password,
            'is_active' => true,
            'status' => PbUser::STATUS_ACTIVE,
            'role_id' => $invite->getAttribute('role_id'),
            'email_verified_at' => now(),
        ];

        if ($user instanceof PbUser) {
            $user->fill($attributes)->save();
        } else {
            /** @var PbUser $user */
            $user = $userClass::query()->create(['email' => $email] + $attributes);
        }

        $invite->forceFill(['accepted_at' => now()])->save();

        return $user;
    }

    private function mail(PbUserInvite $invite, string $plainToken): void
    {
        try {
            $mailer = app(PageBuilderMailer::class);
            if (! $mailer->configured()) {
                return;
            }

            $link = e($this->acceptUrl($invite, $plainToken));
            $html = '<p>You have been invited to join.</p>'
                .'<p>Click the link below to set your password and activate your account:</p>'
                .'<p><a href="'.$link.'">'.$link.'</a></p>'
                .'<p>This invitation expires on '.e((string) $invite->getAttribute('expires_at')).'.</p>';

            $mailer->send((string) $invite->getAttribute('email'), 'You\'re invited', $html);
        } catch (\Throwable) {
            // Best-effort; the admin can resend.
        }
    }
}
