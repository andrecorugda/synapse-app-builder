<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Tests\Fixtures;

use Illuminate\Support\Collection;

/**
 * Stands in for spatie/laravel-permission's PermissionRegistrar (aliased to
 * its class name in tests): only getPermissions(['name' => …]) is used.
 */
class FakePermissionRegistrar
{
    /** @var list<string> */
    public static array $names = [];

    /**
     * @param  array<string,mixed>  $params
     * @return Collection<int,object>
     */
    public function getPermissions(array $params = []): Collection
    {
        return collect(self::$names)
            ->filter(fn (string $name): bool => ! isset($params['name']) || $params['name'] === $name)
            ->map(fn (string $name): object => (object) ['name' => $name])
            ->values();
    }
}
