<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Support;

/**
 * Resolves the package's configurable connection + table names so a host app
 * can relocate / rename the pages table from config without editing the
 * package's migrations or models.
 */
final class Schema
{
    public static function connection(): ?string
    {
        /** @var string|null */
        return config('ai-page-builder.database.connection');
    }

    /**
     * Map a logical table key (pages) to its configured physical name.
     */
    public static function table(string $key): string
    {
        /** @var array<string,string> $tables */
        $tables = config('ai-page-builder.database.tables', []);

        return $tables[$key] ?? $key;
    }
}
