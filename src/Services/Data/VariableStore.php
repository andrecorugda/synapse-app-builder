<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services\Data;

use Andre\AiPageBuilder\Flow\WatcherDispatcher;
use Andre\AiPageBuilder\Models\Variable;

/**
 * Read/write access to persistent, app-wide global variables. The single
 * source of truth shared by Flows (the `globals` root + SetVariableNode),
 * Functions, and the Filament admin.
 *
 * The full key→value map is memoized on the instance (the service is a
 * singleton) and flushed on every write so reads stay cheap within a request.
 */
class VariableStore
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /**
     * Resolve the model class so a host app can swap in a subclass.
     *
     * @return class-string<Variable>
     */
    private function model(): string
    {
        /** @var class-string<Variable> */
        return config('ai-page-builder.models.variable', Variable::class);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Upsert a global by key. When `$type` is null it is inferred from the
     * PHP value. The cache is flushed so subsequent reads see the new value.
     */
    public function set(string $key, mixed $value, ?string $type = null): Variable
    {
        $type ??= $this->inferType($value);

        $modelClass = $this->model();

        // Snapshot the prior value before the write so state watchers can see
        // the transition (and so we can skip firing when nothing changed).
        $existed = $this->has($key);
        $old = $existed ? $this->get($key) : null;

        /** @var Variable $variable */
        $variable = $modelClass::query()->updateOrCreate(
            ['key' => $key],
            [
                'type' => $type,
                'value' => Variable::castForStorage($value, $type),
            ],
        );

        $this->cache = null;

        // Fire state watchers on a real change (or first write). Resolved lazily
        // to avoid a construction cycle (WatcherDispatcher → FlowManager → …
        // → VariableStore). The dispatcher's depth guard bounds cascades, and
        // the change check keeps a watcher that re-sets the same value from
        // looping.
        if (! $existed || $old != $value) {
            app(WatcherDispatcher::class)->dispatchStateChange($key, $old, $value);
        }

        return $variable;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function forget(string $key): void
    {
        $this->model()::query()->where('key', $key)->delete();

        $this->cache = null;
    }

    /**
     * The full map of key → typed value. Memoized; first load is guarded so a
     * missing table (during boot / before migration) never throws.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $this->cache = $this->model()::query()
                ->get()
                ->mapWithKeys(fn (Variable $v): array => [$v->key => $v->typedValue()])
                ->all();
        } catch (\Throwable) {
            return [];
        }

        return $this->cache;
    }

    private function inferType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
