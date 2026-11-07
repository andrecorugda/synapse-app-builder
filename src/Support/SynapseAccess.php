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
 *  - synapse.manage-secrets      — credentials, API tokens
 *  - synapse.manage-users        — app users, roles, invites, self-registration
 *  - synapse.manage-code         — functions (incl. the PHP runtime), app import
 *  - synapse.manage-settings     — builder settings, theme, app export
 *  - synapse.manage-data-sources — external (existing-table) collections
 *
 * A finer ability is always checked ON TOP of the admin one: it can narrow
 * access, never widen it.
 *
 * The fallback ("follow the admin decision") is skipped — the finer ability
 * DENIES — when `filament.fine_abilities_fallback` is `deny`, or when the host
 * has registered a permission with the ability's name in spatie/laravel-permission
 * (its Gate::before hook answers null for a permission nobody holds, which
 * would otherwise reach our fallback and allow).
 */
final class SynapseAccess
{
    public const ADMIN = 'admin';

    public const MANAGE_SECRETS = 'manage-secrets';

    public const MANAGE_USERS = 'manage-users';

    public const MANAGE_CODE = 'manage-code';

    public const MANAGE_SETTINGS = 'manage-settings';

    public const MANAGE_DATA_SOURCES = 'manage-data-sources';

    /** The finer scopes (everything except the base admin scope). */
    public const FINE_SCOPES = [
        self::MANAGE_SECRETS,
        self::MANAGE_USERS,
        self::MANAGE_CODE,
        self::MANAGE_SETTINGS,
        self::MANAGE_DATA_SOURCES,
    ];

    /** `filament.fine_abilities_fallback`: an undefined finer ability follows synapse.admin. */
    public const FALLBACK_ADMIN = 'admin';

    /** `filament.fine_abilities_fallback`: an undefined finer ability denies. */
    public const FALLBACK_DENY = 'deny';

    /**
     * The panel a scope is being decided for, while allows() asks the Gate.
     * The fallback gate reads it so a plugin `->authorize()` callback on a
     * non-default panel is honoured for the finer abilities too.
     */
    private static ?Panel $deciding = null;

    /** Synapse's own deny-by-default `synapse.admin` definition (null when the host defined it first). */
    private static ?Closure $defaultAdminGate = null;

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
        self::$defaultAdminGate = null;
        if (! Gate::has($admin)) {
            self::$defaultAdminGate = static fn (mixed $user = null): bool => false;
            Gate::define($admin, self::$defaultAdminGate);
        }

        foreach (self::FINE_SCOPES as $scope) {
            $ability = self::ability($scope);
            if ($ability !== $admin && ! Gate::has($ability)) {
                // Not separately defined: falls back to the admin decision
                // (unless the host opted out or holds it as a permission).
                Gate::define($ability, static fn (mixed $user = null): bool => $user instanceof Authenticatable
                    && self::fallbackAllows($user, $ability));
            }
        }
    }

    /**
     * The decision for a finer ability the host did not define with
     * Gate::define(): follow the admin decision on the panel being decided,
     * unless the host chose `deny` or registered a permission of that name.
     */
    private static function fallbackAllows(Authenticatable $user, string $ability): bool
    {
        $mode = config('ai-page-builder.filament.fine_abilities_fallback', self::FALLBACK_ADMIN);
        if (! is_string($mode) || strtolower(trim($mode)) !== self::FALLBACK_ADMIN) {
            // Anything but the documented `admin` value fails closed.
            return false;
        }

        $panel = self::$deciding ?? self::currentPanel();

        // Checked first, whatever admitted the user: the host manages this
        // ability as a permission (spatie/laravel-permission), and a user who
        // held it was already allowed by the package's Gate::before hook, so
        // reaching here means they do not hold it.
        if (self::hostHasPermissionNamed($ability)) {
            return false;
        }

        // Admitted by the plugin's ->authorize() callback: follow that callback.
        if (self::pluginCallback($panel) !== null) {
            return self::allowsAdmin($user, $panel);
        }

        if (self::adminComesFromBeforeHook()) {
            // The host never defined `synapse.admin`, so whoever passes it was let in
            // by a Gate::before hook — a permission system. Such a host grants the
            // finer abilities the same way; one its hook did not grant is denied.
            return false;
        }

        return self::allowsAdmin($user, $panel);
    }

    /**
     * True while `synapse.admin` is still Synapse's own deny-everyone
     * definition: any user who passes it was let in by a Gate::before hook.
     */
    private static function adminComesFromBeforeHook(): bool
    {
        if (self::$defaultAdminGate === null) {
            return false;
        }

        return (Gate::abilities()[self::ability()] ?? null) === self::$defaultAdminGate;
    }

    /**
     * Has the host registered a spatie/laravel-permission permission with this
     * name (on any guard)? Fails closed to "yes" when the lookup itself breaks,
     * so a broken permission store never silently widens access.
     */
    private static function hostHasPermissionNamed(string $ability): bool
    {
        $registrar = 'Spatie\\Permission\\PermissionRegistrar';
        if (! class_exists($registrar)) {
            return false;
        }

        try {
            /** @var object $instance */
            $instance = app($registrar);
            if (! method_exists($instance, 'getPermissions')) {
                return true;
            }

            $found = $instance->getPermissions(['name' => $ability]);

            return is_countable($found) ? count($found) > 0 : true;
        } catch (Throwable) {
            return true;
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

        $previous = self::$deciding;
        self::$deciding = $panel ?? self::currentPanel();

        try {
            return Gate::forUser($user)->allows(self::ability($scope));
        } finally {
            self::$deciding = $previous;
        }
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
