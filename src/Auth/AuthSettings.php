<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Auth;

use Andre\AiPageBuilder\Services\Settings;

/**
 * Resolved end-user auth policy: the admin's runtime choices (stored via the
 * Settings service under dotted `auth.*` keys, edited on the Identity & Auth
 * screen) layered over the install-time config defaults. Every consumer — the
 * login/registration/reset controllers, the route gates, the login view — reads
 * policy through here so there is one source of truth.
 */
class AuthSettings
{
    public const MODE_OPEN = 'open';

    public const MODE_APPROVAL = 'approval';

    public const MODE_INVITE_ONLY = 'invite_only';

    public function __construct(private readonly Settings $settings) {}

    public function passwordLoginEnabled(): bool
    {
        return (bool) $this->settings->get(
            'auth.password_login',
            config('ai-page-builder.auth.password_login', true),
        );
    }

    public function registrationEnabled(): bool
    {
        return (bool) $this->settings->get(
            'auth.registration_enabled',
            config('ai-page-builder.auth.registration.enabled', false),
        );
    }

    /** Onboarding model: open | approval | invite_only. */
    public function registrationMode(): string
    {
        $mode = (string) $this->settings->get(
            'auth.registration_mode',
            config('ai-page-builder.auth.registration.mode', self::MODE_APPROVAL),
        );

        return in_array($mode, [self::MODE_OPEN, self::MODE_APPROVAL, self::MODE_INVITE_ONLY], true)
            ? $mode
            : self::MODE_APPROVAL;
    }

    /** Public sign-up is possible only when enabled AND not invite-only. */
    public function publicRegistrationAllowed(): bool
    {
        return $this->registrationEnabled() && $this->registrationMode() !== self::MODE_INVITE_ONLY;
    }

    /** New sign-ups need admin approval (status=pending) before they can log in. */
    public function registrationNeedsApproval(): bool
    {
        return $this->registrationMode() === self::MODE_APPROVAL;
    }

    /** Role slug assigned to a newly-registered / invited user, if any. */
    public function defaultRole(): ?string
    {
        $role = $this->settings->get(
            'auth.default_role',
            config('ai-page-builder.auth.registration.default_role'),
        );

        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * Allow-list of lower-cased email domains for registration. Empty = any.
     *
     * @return array<int,string>
     */
    public function allowedEmailDomains(): array
    {
        $domains = $this->settings->get(
            'auth.allowed_email_domains',
            config('ai-page-builder.auth.registration.allowed_email_domains', []),
        );

        if (! is_array($domains)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($d): string => strtolower(trim((string) $d)),
            $domains,
        )));
    }

    public function emailDomainAllowed(string $email): bool
    {
        $domains = $this->allowedEmailDomains();
        if ($domains === []) {
            return true;
        }

        $at = strrchr(strtolower($email), '@');

        return $at !== false && in_array(substr($at, 1), $domains, true);
    }

    public function resetTokenTtl(): int
    {
        return (int) $this->settings->get(
            'auth.reset_token_ttl',
            config('ai-page-builder.auth.reset.token_ttl', 3600),
        );
    }
}
