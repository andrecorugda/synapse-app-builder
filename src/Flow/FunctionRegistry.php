<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

/**
 * Holds developer-registered native PHP callables keyed by a string identifier.
 *
 * Register at boot (e.g. in AppServiceProvider::boot):
 *
 *   app(\Andre\AiPageBuilder\Flow\FunctionRegistry::class)
 *       ->register('my-fn', fn (array $args, FlowContext $ctx): mixed => ...);
 *
 * The callable signature is: callable(array $args, FlowContext $ctx): mixed
 * Passing FlowContext is optional — callers that do not need it can omit the
 * second parameter (PHP does not enforce arity on callables).
 */
class FunctionRegistry
{
    /** @var array<string,callable> */
    private array $callables = [];

    /**
     * Register a callable under a string key. Overwrites any prior registration
     * for the same key.
     */
    public function register(string $key, callable $fn): void
    {
        $this->callables[$key] = $fn;
    }

    /**
     * Retrieve a registered callable by key. Returns null when not found.
     */
    public function get(string $key): ?callable
    {
        return $this->callables[$key] ?? null;
    }

    /**
     * Check whether a key has been registered.
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->callables);
    }

    /**
     * Return all registered keys.
     *
     * @return array<int,string>
     */
    public function keys(): array
    {
        return array_keys($this->callables);
    }
}
