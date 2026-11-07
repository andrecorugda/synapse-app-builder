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
 *
 * Visibility: a State is SERVER-ONLY unless flagged `is_public`. Server code
 * (flows, functions, the admin) reads every State; rendered pages receive only
 * the public ones ({@see publicAll()}), and a page's live value may stand in for
 * a State during a flow run only when that State is public or not persisted at
 * all ({@see clientOverlay()}) — so a secret or a server-side setting such as a
 * tax rate can neither be read nor overridden by a visitor.
 */
class VariableStore
{
    /** @var array<string,mixed>|null */
    private ?array $cache = null;

    /** @var array<string,bool>|null key => is_public */
    private ?array $visibility = null;

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
    /**
     * `$public` sets the State's client visibility; null leaves an existing
     * State's visibility untouched (a new State is server-only).
     */
    public function set(string $key, mixed $value, ?string $type = null, ?bool $public = null): Variable
    {
        $type ??= $this->inferType($value);

        $modelClass = $this->model();

        // Snapshot the prior value before the write so state watchers can see
        // the transition (and so we can skip firing when nothing changed).
        $existed = $this->has($key);
        $old = $existed ? $this->get($key) : null;

        $attributes = [
            'type' => $type,
            'value' => Variable::castForStorage($value, $type),
        ];
        if ($public !== null) {
            $attributes['is_public'] = $public;
        }

        /** @var Variable $variable */
        $variable = $modelClass::query()->updateOrCreate(['key' => $key], $attributes);

        $this->flush();

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

        $this->flush();
    }

    /** Mark States client-visible (true) or server-only (false). Returns the number changed. */
    public function setPublic(array $keys, bool $public): int
    {
        $changed = $this->model()::query()->whereIn('key', $keys)->update(['is_public' => $public]);

        $this->flush();

        return $changed;
    }

    /** True when the State exists and is flagged client-visible. */
    public function isPublic(string $key): bool
    {
        return $this->visibility()[$key] ?? false;
    }

    /**
     * Only the client-visible States (key → typed value) — what a rendered page
     * may receive.
     *
     * @return array<string,mixed>
     */
    public function publicAll(): array
    {
        return array_intersect_key($this->all(), array_filter($this->visibility()));
    }

    /**
     * The part of a visitor-supplied state map that may shadow persisted States
     * during a flow run: keys of client-visible States and keys that are not a
     * persisted State at all (page-local values). A server-only State is never
     * overridden by the client.
     *
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    public function clientOverlay(array $values): array
    {
        $visibility = $this->visibility();

        return array_filter(
            $values,
            static fn (int|string $key): bool => ($visibility[(string) $key] ?? true) === true,
            ARRAY_FILTER_USE_KEY,
        );
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
            $rows = $this->model()::query()->get();
        } catch (\Throwable) {
            return [];
        }

        $this->cache = $rows->mapWithKeys(fn (Variable $v): array => [$v->key => $v->typedValue()])->all();
        $this->visibility = $rows->mapWithKeys(fn (Variable $v): array => [$v->key => (bool) $v->getAttribute('is_public')])->all();

        return $this->cache;
    }

    /** @return array<string,bool> key => is_public */
    private function visibility(): array
    {
        $this->all();

        return $this->visibility ?? [];
    }

    private function flush(): void
    {
        $this->cache = null;
        $this->visibility = null;
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
