<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Capabilities;

use Andre\AiPageBuilder\Flow\NodeRegistry;
use RuntimeException;

/**
 * Holds the curated function helpers — the eval-free toolbox a Function can call
 * inside the expression sandbox (db.*, ui.*, auth.*, http.*, util.*). Each helper
 * pairs a {@see CapabilityDefinition} (kind 'helper', for the editor dropdown and
 * the MCP/AI catalogue) with a PHP callable.
 *
 * Extensible by design: `PageBuilder::registerHelper(...)` resolves to `register()`
 * here, so host apps and third-party packages add helpers without a core change —
 * the same story as {@see NodeRegistry} for nodes.
 */
class HelperRegistry
{
    /** @var array<string,array{definition:CapabilityDefinition,callable:callable}> */
    private array $helpers = [];

    public function register(CapabilityDefinition $definition, callable $callable): void
    {
        $this->helpers[$definition->key] = ['definition' => $definition, 'callable' => $callable];
    }

    public function has(string $key): bool
    {
        return isset($this->helpers[$key]);
    }

    /** Invoke a registered helper by key. Throws if unknown. */
    public function call(string $key, mixed ...$args): mixed
    {
        if (! isset($this->helpers[$key])) {
            throw new RuntimeException("Unknown helper [{$key}].");
        }

        return ($this->helpers[$key]['callable'])(...$args);
    }

    /**
     * Every helper definition, sorted by category order then label — the shape
     * the function-editor dropdown and the MCP tool catalogue consume.
     *
     * @return array<int,CapabilityDefinition>
     */
    public function definitions(): array
    {
        $defs = array_map(static fn (array $h): CapabilityDefinition => $h['definition'], array_values($this->helpers));

        usort($defs, static function (CapabilityDefinition $a, CapabilityDefinition $b): int {
            return [$a->category->order(), $a->label] <=> [$b->category->order(), $b->label];
        });

        return $defs;
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_keys($this->helpers);
    }
}
