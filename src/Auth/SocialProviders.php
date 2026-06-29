<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Auth;

use Andre\AiPageBuilder\Services\Settings;
use Laravel\Socialite\SocialiteManager;

/**
 * Resolves the SSO provider configuration: credentials (from env/config) layered
 * with the admin's runtime overrides (the per-provider `enabled` flag + the
 * org/domain/tenant restrictions, edited on the Identity & Auth screen). Also
 * the single gate for whether SSO is even possible — Socialite is OPTIONAL, so
 * a provider is only "usable" when it is enabled, credentialed, and the
 * laravel/socialite package is installed.
 */
class SocialProviders
{
    /** @var array<int,string> */
    public const DRIVERS = ['google', 'microsoft', 'github'];

    /** @var array<string,string> */
    private const LABELS = ['google' => 'Google', 'microsoft' => 'Microsoft', 'github' => 'GitHub'];

    public function __construct(private readonly Settings $settings) {}

    public function socialiteAvailable(): bool
    {
        return class_exists(SocialiteManager::class);
    }

    public function isValidDriver(string $provider): bool
    {
        return in_array($provider, self::DRIVERS, true);
    }

    public function label(string $provider): string
    {
        return self::LABELS[$provider] ?? ucfirst($provider);
    }

    /**
     * Merged provider config: env/config credentials + Settings-overridable
     * `enabled` flag and restrictions.
     *
     * @return array<string,mixed>
     */
    public function config(string $provider): array
    {
        $base = (array) config("ai-page-builder.auth.providers.{$provider}", []);

        $base['enabled'] = (bool) $this->settings->get(
            "auth.providers.{$provider}.enabled",
            $base['enabled'] ?? false,
        );

        foreach (['allowed_domains', 'tenant', 'allowed_orgs'] as $key) {
            if (array_key_exists($key, $base)) {
                $base[$key] = $this->settings->get("auth.providers.{$provider}.{$key}", $base[$key]);
            }
        }

        return $base;
    }

    public function hasCredentials(string $provider): bool
    {
        $c = $this->config($provider);

        return ! empty($c['client_id']) && ! empty($c['client_secret']);
    }

    public function enabledFlag(string $provider): bool
    {
        return (bool) ($this->config($provider)['enabled'] ?? false);
    }

    /** A provider can be offered only when valid, enabled, credentialed, and Socialite is present. */
    public function usable(string $provider): bool
    {
        return $this->isValidDriver($provider)
            && $this->socialiteAvailable()
            && $this->enabledFlag($provider)
            && $this->hasCredentials($provider);
    }

    /**
     * Providers to render as buttons on the login page.
     *
     * @return array<int,array{key:string,label:string}>
     */
    public function usableList(): array
    {
        $out = [];
        foreach (self::DRIVERS as $driver) {
            if ($this->usable($driver)) {
                $out[] = ['key' => $driver, 'label' => $this->label($driver)];
            }
        }

        return $out;
    }

    public function callbackPath(string $provider): string
    {
        $login = trim((string) config('ai-page-builder.auth.login_path', 'login'), '/');

        return $login.'/sso/'.$provider.'/callback';
    }

    /**
     * Feed the provider's credentials into Socialite's runtime config so the
     * driver resolves (Socialite reads config('services.{provider}')). Called
     * just before redirect / callback.
     */
    public function configureSocialite(string $provider): void
    {
        $c = $this->config($provider);

        $service = [
            'client_id' => $c['client_id'] ?? null,
            'client_secret' => $c['client_secret'] ?? null,
            'redirect' => ! empty($c['redirect']) ? $c['redirect'] : url($this->callbackPath($provider)),
        ];

        if ($provider === 'microsoft' && ! empty($c['tenant'])) {
            $service['tenant'] = $c['tenant'];
        }

        config(["services.{$provider}" => $service]);
    }

    /** @return array<int,string> lower-cased allowed email domains for a provider */
    public function allowedDomains(string $provider): array
    {
        return $this->stringList($this->config($provider)['allowed_domains'] ?? []);
    }

    /** @return array<int,string> lower-cased allowed GitHub org logins */
    public function allowedOrgs(string $provider): array
    {
        return $this->stringList($this->config($provider)['allowed_orgs'] ?? []);
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($v): string => strtolower(trim((string) $v)),
            $value,
        )));
    }
}
