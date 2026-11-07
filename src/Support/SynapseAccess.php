<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Support;

use Andre\AiPageBuilder\Filament\AiPageBuilderPlugin;
use Closure;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * The ONE place that decides who may use the Synapse builder (its Filament
 * screens and the in-panel HTTP endpoints).
 *
 * Deny by default. A host grants access by defining the `synapse.admin` Gate
 * ability (or by passing `->authorize(fn ($user) => …)` to the plugin):
 *
 *     Gate::define('synapse.admin', fn ($user) => $user->is_admin);
 *
 * Sensitive areas use a finer ability that falls back to the admin check
 * unless the host defines it separately:
 *
 *  - synapse.manage-secrets  — credentials, API tokens
 *  - synapse.manage-users    — app users, roles, invites
 *  - synapse.manage-code     — functions (incl. the PHP runtime), app import
 *  - synapse.manage-settings — builder settings, theme, app export
 *
 * A finer ability is always checked ON TOP of the admin one: it can narrow
 * access, never widen it.
 */
final class SynapseAccess
{
    public const ADMIN = 'admin';

    public const MANAGE_SECRETS = 'manage-secrets';

    public const MANAGE_USERS = 'manage-users';

    public const MANAGE_CODE = 'manage-code';

    public const MANAGE_SETTINGS = 'manage-settings';

    /** The finer scopes (everything except the base admin scope). */
    public const FINE_SCOPES = [
        self::MANAGE_SECRETS,
        self::MANAGE_USERS,
        self::MANAGE_CODE,
        self::MANAGE_SETTINGS,
    ];

    /**
     * The Gate ability name for a scope (configurable so a host can map
     * Synapse onto its own permission names).
     */
    public static function ability(string $scope = self::ADMIN): string
    {
        if ($scope === self::ADMIN) {
            return (string) config('ai-page-builder.filament.ability', 'synapse.admin');
        }

        return (string) config('ai-page-builder.filament.abilities.'.$scope, 'synapse.'.$scope);
    }

    /**
     * Register the deny-by-default abilities — only those the host has not
     * defined already (a host definition made later simply replaces ours).
     */
    public static function defineDefaultGates(): void
    {
        $admin = self::ability();
        if (! Gate::has($admin)) {
            Gate::define($admin, static fn (mixed $user = null): bool => false);
        }

        foreach (self::FINE_SCOPES as $scope) {
            $ability = self::ability($scope);
            if ($ability !== $admin && ! Gate::has($ability)) {
                // Not separately defined: falls back to the admin decision.
                Gate::define($ability, static fn (mixed $user = null): bool => $user instanceof Authenticatable
                    && self::allowsAdmin($user));
            }
        }
    }

    /**
     * May this user use the given Synapse scope?
     */
    public static function allows(?Authenticatable $user, string $scope = self::ADMIN, ?Panel $panel = null): bool
    {
        if ($user === null || ! self::allowsAdmin($user, $panel)) {
            return false;
        }

        if ($scope === self::ADMIN) {
            return true;
        }

        return Gate::forUser($user)->allows(self::ability($scope));
    }

    /**
     * The admin decision: the plugin's `->authorize()` callback when one is
     * set on the panel, otherwise the `synapse.admin` Gate ability.
     */
    public static function allowsAdmin(Authenticatable $user, ?Panel $panel = null): bool
    {
        $callback = self::pluginCallback($panel ?? self::currentPanel());
        if ($callback !== null) {
            return (bool) $callback($user);
        }

        return Gate::forUser($user)->allows(self::ability());
    }

    /**
     * Check a scope for the CURRENT Filament panel's own user (Synapse
     * resources, pages and relation managers call this).
     */
    public static function check(string $scope = self::ADMIN): bool
    {
        $panel = self::currentPanel();
        if ($panel === null) {
            return false;
        }

        try {
            $user = $panel->auth()->user();
        } catch (Throwable) {
            return false;
        }

        return self::allows($user, $scope, $panel);
    }

    /**
     * The panel-route check (EnsureSynapseAdmin): the user must be a Filament
     * user allowed into a panel that runs Synapse AND pass the scope there.
     * Fails closed when Filament is missing or no such panel exists.
     */
    public static function allowsPanelRoute(string $scope = self::ADMIN): bool
    {
        foreach (self::candidatePanels() as $panel) {
            try {
                $user = $panel->auth()->user();
            } catch (Throwable) {
                continue;
            }

            if ($user instanceof FilamentUser
                && $user instanceof Authenticatable
                && $user->canAccessPanel($panel)
                && self::allows($user, $scope, $panel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Panels the in-panel endpoints belong to: the configured one, or else
     * every panel that registers the Synapse plugin.
     *
     * @return list<Panel>
     */
    private static function candidatePanels(): array
    {
        if (! class_exists(Filament::class)) {
            return [];
        }

        try {
            $id = config('ai-page-builder.filament.panel');
            if (is_string($id) && $id !== '') {
                $panel = Filament::getPanel($id, isStrict: false);

                return $panel instanceof Panel && $panel->getId() === $id ? [$panel] : [];
            }

            return array_values(array_filter(
                Filament::getPanels(),
                static fn (Panel $panel): bool => $panel->hasPlugin(AiPageBuilderPlugin::ID),
            ));
        } catch (Throwable) {
            return [];
        }
    }

    private static function currentPanel(): ?Panel
    {
        if (! class_exists(Filament::class)) {
            return null;
        }

        try {
            return Filament::getCurrentOrDefaultPanel();
        } catch (Throwable) {
            return null;
        }
    }

    private static function pluginCallback(?Panel $panel): ?Closure
    {
        if ($panel === null || ! $panel->hasPlugin(AiPageBuilderPlugin::ID)) {
            return null;
        }

        $plugin = $panel->getPlugin(AiPageBuilderPlugin::ID);

        return $plugin instanceof AiPageBuilderPlugin ? $plugin->getAuthorizeUsing() : null;
    }
}
