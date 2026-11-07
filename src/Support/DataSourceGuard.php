<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Support;

use Andre\AiPageBuilder\Exceptions\DataSourceRefusedException;
use Andre\AiPageBuilder\Models\PbApiToken;
use Andre\AiPageBuilder\Models\PbModel;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Decides which database table a collection may be bound to. Enforced at the
 * DATA layer (PbModel saving, Record::for, SchemaSynchronizer), so every path
 * — the admin form, an imported app, an AI plan, a row written straight to the
 * database — goes through it.
 *
 *  - Synapse's own tables and the host's auth tables are ALWAYS refused, for
 *    managed and external collections alike.
 *  - An external collection must name a connection and table the host listed
 *    in `ai-page-builder.data.external` (nothing is listed by default).
 *  - An external collection is read-only unless `data.external.writable` is on
 *    and its own Read-only toggle is off.
 */
final class DataSourceGuard
{
    /** Table names Synapse ships, by the logical key Schema::table() maps. */
    private const SYNAPSE_TABLE_KEYS = [
        'pages', 'page_revisions', 'media', 'flows', 'flow_runs', 'functions',
        'models', 'fields', 'record_revisions', 'variables', 'settings',
        'users', 'roles', 'permissions', 'password_resets', 'user_invites',
        'credentials', 'partials', 'schedules', 'watchers',
    ];

    /** Host tables that hold sign-in state or grants — never mappable. */
    private const HOST_AUTH_TABLES = [
        'users', 'password_reset_tokens', 'password_resets', 'sessions',
        'personal_access_tokens', 'migrations',
        // Laravel Passport.
        'oauth_access_tokens', 'oauth_auth_codes', 'oauth_clients',
        'oauth_refresh_tokens', 'oauth_personal_access_clients', 'oauth_device_codes',
        // spatie/laravel-permission defaults (configured names are added too).
        'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
    ];

    /**
     * Refuse (throw) when the collection's table may not be used.
     *
     * @throws DataSourceRefusedException
     */
    public static function assertAllowed(PbModel $model): void
    {
        $reason = self::refusal($model);
        if ($reason !== null) {
            throw new DataSourceRefusedException($reason);
        }
    }

    /**
     * Why the collection's table may not be used, or null when it may.
     */
    public static function refusal(PbModel $model): ?string
    {
        $table = trim((string) ($model->getAttribute('table_name') ?? ''));

        if (! $model->isExternal()) {
            if ($table === '') {
                return null;
            }

            return self::isProtectedTable($table, Schema::connection())
                ? "Collection '{$model->key}' cannot use the table '{$table}': it belongs to Synapse or to the app's sign-in."
                : null;
        }

        return self::externalRefusal($model->getAttribute('source_connection'), $table);
    }

    /**
     * Why an external (connection, table) pair may not be mapped, or null.
     */
    public static function externalRefusal(mixed $connection, string $table): ?string
    {
        $table = trim($table);
        if ($table === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_$]*(\.[A-Za-z_][A-Za-z0-9_$]*)?$/', $table) !== 1) {
            return 'Enter a plain table name (letters, digits and underscores).';
        }

        $connection = self::connectionName(is_string($connection) && $connection !== '' ? $connection : null);

        if (self::isProtectedTable($table, $connection)) {
            return "The table '{$table}' belongs to Synapse or to the app's sign-in and can never be mapped.";
        }

        if (! in_array($connection, self::allowedConnections(), true)) {
            return "The connection '{$connection}' is not open to external collections (ai-page-builder.data.external.connections).";
        }

        if (! self::tableListed($connection, $table)) {
            return "The table '{$table}' is not open to external collections on '{$connection}' (ai-page-builder.data.external.tables).";
        }

        return null;
    }

    /**
     * May an external collection be written at all (the host's switch)?
     */
    public static function externalWritesEnabled(): bool
    {
        return (bool) config('ai-page-builder.data.external.writable', false);
    }

    /**
     * Connections builders may choose from (only those that exist).
     *
     * @return list<string>
     */
    public static function allowedConnections(): array
    {
        $configured = (array) config('ai-page-builder.data.external.connections', []);
        $existing = array_keys((array) config('database.connections', []));

        return array_values(array_filter(
            array_map(static fn (mixed $c): string => is_string($c) ? $c : '', $configured),
            static fn (string $c): bool => $c !== '' && in_array($c, $existing, true),
        ));
    }

    /**
     * Is this table Synapse's own or one of the host's auth tables?
     */
    public static function isProtectedTable(string $table, ?string $connection = null): bool
    {
        $protected = self::protectedTables();
        $base = self::normalize($table);
        $prefix = self::normalize((string) config('database.connections.'.self::connectionName($connection).'.prefix', ''));

        return isset($protected[$base]) || ($prefix !== '' && isset($protected[$prefix.$base]));
    }

    /**
     * Every protected table name, bare and with each connection's table prefix.
     *
     * @return array<string,true>
     */
    public static function protectedTables(): array
    {
        $names = self::HOST_AUTH_TABLES;

        foreach (self::SYNAPSE_TABLE_KEYS as $key) {
            $names[] = Schema::table($key);
        }
        foreach ((array) config('ai-page-builder.database.tables', []) as $name) {
            $names[] = $name;
        }
        $names[] = PbApiToken::tableName();

        foreach ((array) config('auth.providers', []) as $provider) {
            if (! is_array($provider)) {
                continue;
            }
            if (is_string($provider['table'] ?? null)) {
                $names[] = $provider['table'];
            }
            $modelClass = $provider['model'] ?? null;
            if (is_string($modelClass) && is_subclass_of($modelClass, Model::class)) {
                try {
                    $names[] = (new $modelClass)->getTable();
                } catch (Throwable) {
                    // A model that cannot be built has no table to add.
                }
            }
        }
        foreach ((array) config('auth.passwords', []) as $broker) {
            if (is_array($broker) && is_string($broker['table'] ?? null)) {
                $names[] = $broker['table'];
            }
        }
        $names[] = config('session.table');
        $migrations = config('database.migrations');
        $names[] = is_array($migrations) ? ($migrations['table'] ?? null) : $migrations;
        foreach ((array) config('permission.table_names', []) as $name) {
            $names[] = $name;
        }
        foreach ((array) config('ai-page-builder.data.external.protected_tables', []) as $name) {
            $names[] = $name;
        }

        $prefixes = [''];
        foreach ((array) config('database.connections', []) as $conn) {
            if (is_array($conn) && is_string($conn['prefix'] ?? null) && $conn['prefix'] !== '') {
                $prefixes[] = self::normalize($conn['prefix']);
            }
        }

        $out = [];
        foreach ($names as $name) {
            if (! is_string($name) || trim($name) === '') {
                continue;
            }
            $bare = self::normalize($name);
            foreach (array_unique($prefixes) as $prefix) {
                $out[$prefix.$bare] = true;
            }
        }

        return $out;
    }

    /** The real connection name (null = the default connection). */
    public static function connectionName(?string $connection): string
    {
        return $connection !== null && $connection !== ''
            ? $connection
            : (string) config('database.default');
    }

    private static function tableListed(string $connection, string $table): bool
    {
        $wanted = self::normalize($table);
        $whole = strtolower(trim($table));

        foreach ((array) config('ai-page-builder.data.external.tables', []) as $key => $entry) {
            if (is_string($key) && $key !== $connection) {
                continue; // a list for another connection
            }
            foreach ((array) $entry as $allowed) {
                if (! is_string($allowed)) {
                    continue;
                }
                $allowed = strtolower(trim($allowed));
                if ($allowed === '*' || $allowed === $whole || (! str_contains($allowed, '.') && $allowed === $wanted && ! str_contains($whole, '.'))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Lower-case, unquoted, last segment of a possibly schema-qualified name. */
    private static function normalize(string $name): string
    {
        $name = strtolower(trim(str_replace(['`', '"', '[', ']'], '', $name)));
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr($name, $dot + 1);
    }
}
