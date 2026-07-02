<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Flow\Concerns\MatchesRecordCriteria;
use Andre\AiPageBuilder\Models\Flow;
use Andre\AiPageBuilder\Models\FlowFunction;
use Andre\AiPageBuilder\Models\Watcher;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Andre\AiPageBuilder\Services\ScheduleRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Runs the flows/functions bound to reactive {@see Watcher} rows.
 *
 * Two entry points:
 *   • {@see dispatchCollectionEvent} — a record was created/updated/deleted in a
 *     collection ({@see RecordObserver}). Each watcher binds ONE event to ONE
 *     target, so created/updated/deleted can each run a *different* flow.
 *   • {@see dispatchStateChange} — a global variable changed
 *     ({@see VariableStore::set}).
 *
 * A dispatched flow may itself write records or set state, which would re-fire a
 * watcher — so dispatch is bounded by a small re-entrancy depth guard. Never
 * throws: a misbehaving flow must not break the originating write.
 */
class WatcherDispatcher
{
    use MatchesRecordCriteria;

    /**
     * How deep cascading watcher dispatches may nest before the guard trips.
     */
    private const MAX_DEPTH = 3;

    private static int $depth = 0;

    public function __construct(
        private readonly FlowManager $flows,
        private readonly FlowRunner $runner,
    ) {}

    /**
     * Fire every matching collection watcher for a record event.
     *
     * @param  array<string,mixed>  $record  the new record state
     * @param  array<string,mixed>  $old  the previous state (empty on create)
     */
    public function dispatchCollectionEvent(string $collectionKey, string $event, array $record, array $old = []): void
    {
        if (self::$depth >= self::MAX_DEPTH) {
            $this->warnDepth(['collection' => $collectionKey, 'event' => $event]);

            return;
        }

        $watchers = $this->watcherModel()::query()
            ->active()
            ->forCollection($collectionKey, $event)
            ->get();

        if ($watchers->isEmpty()) {
            return;
        }

        $input = [
            'event' => $event,
            'collection' => $collectionKey,
            'record' => $record,
            'old' => $old,
        ];

        self::$depth++;

        try {
            foreach ($watchers as $watcher) {
                $config = (array) ($watcher->config ?? []);

                $criteria = (array) ($config['criteria'] ?? []);
                if ($criteria !== [] && ! $this->matchesCriteria($record, $criteria)) {
                    continue;
                }

                if (! $this->changedFieldsTouched($config['changed'] ?? [], $record, $old)) {
                    continue;
                }

                $this->runTarget($watcher, $input);
            }
        } finally {
            self::$depth--;
        }
    }

    /**
     * `config.changed` narrows an update watcher to fire only when at least one
     * of the named fields actually changed (old ≠ new). No prior state (`$old`
     * empty — i.e. a create) or an empty list means "don't filter": every field
     * of a new record is a change, and deletes have no meaningful diff.
     *
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $old
     */
    private function changedFieldsTouched(mixed $fields, array $record, array $old): bool
    {
        $fields = array_values(array_filter((array) $fields, static fn ($f): bool => is_string($f) && $f !== ''));

        if ($fields === [] || $old === []) {
            return true;
        }

        foreach ($fields as $field) {
            if (($old[$field] ?? null) != ($record[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fire every matching state watcher for a changed global variable. `$old`
     * and `$new` are the typed values before/after the write.
     */
    public function dispatchStateChange(string $stateKey, mixed $old, mixed $new): void
    {
        if (self::$depth >= self::MAX_DEPTH) {
            $this->warnDepth(['state' => $stateKey]);

            return;
        }

        $watchers = $this->watcherModel()::query()
            ->active()
            ->forState($stateKey)
            ->get();

        if ($watchers->isEmpty()) {
            return;
        }

        $input = [
            'event' => 'changed',
            'key' => $stateKey,
            'old' => $old,
            'new' => $new,
        ];

        self::$depth++;

        try {
            foreach ($watchers as $watcher) {
                // Browser-side watchers observe the page's LIVE store and fire
                // from flow-runtime.js — the server write path must skip them
                // or a persisted change would run the target twice.
                if ((($watcher->config['side'] ?? 'server')) === 'client') {
                    continue;
                }

                if (! $this->stateConditionMet($watcher, $old, $new)) {
                    continue;
                }

                $this->runTarget($watcher, $input);
            }
        } finally {
            self::$depth--;
        }
    }

    /**
     * A state watcher may narrow to a sub-`path` of an Object state, a
     * transition (`from`/`to`), and/or an operator test on the new value
     * (`op`/`value`). No condition = fire on any change. When a path is given,
     * conditions apply to the value at that path and the path value must have
     * actually changed.
     */
    private function stateConditionMet(Watcher $watcher, mixed $old, mixed $new): bool
    {
        $config = (array) ($watcher->config ?? []);
        $path = $config['path'] ?? null;

        if (is_string($path) && $path !== '') {
            $old = data_get($old, $path);
            $new = data_get($new, $path);

            // The whole state changed, but not this path — ignore.
            if ($old == $new) {
                return false;
            }
        }

        if (array_key_exists('from', $config) && ! $this->valuesEqual($old, $config['from'])) {
            return false;
        }

        if (array_key_exists('to', $config) && ! $this->valuesEqual($new, $config['to'])) {
            return false;
        }

        if (($config['op'] ?? null) !== null && $config['op'] !== '') {
            return $this->matchesCondition($new, (string) $config['op'], $config['value'] ?? null);
        }

        return true;
    }

    /**
     * Equality that survives the form → JSON boundary. A boolean state value is
     * real JSON `true`/`false`, but the watcher's `from`/`to` come from a text
     * form as the strings "true"/"false" (or "1"/"0"). PHP's loose `==` gets
     * this wrong — `false == "false"` is FALSE because the non-empty string is
     * truthy — so a "fires when the flag turns off" watcher never fired. When
     * either side is a real boolean, compare on boolean meaning; otherwise fall
     * back to normal loose equality.
     */
    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return $this->toBool($a) === $this->toBool($b);
        }

        return $a == $b;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) $value;
    }

    /**
     * Run the watcher's target (flow or function) and stamp its outcome. Each
     * run is isolated — a failure is logged and recorded, never rethrown.
     *
     * @param  array<string,mixed>  $input
     */
    private function runTarget(Watcher $watcher, array $input): void
    {
        $status = 'ok';
        $error = null;

        try {
            match ($watcher->target_type) {
                'flow' => $this->runFlow($watcher, $input),
                'function' => $this->runFunction($watcher, $input),
                default => throw new \RuntimeException(
                    sprintf('Unknown watcher target_type "%s".', $watcher->target_type)
                ),
            };
        } catch (\Throwable $e) {
            $status = 'failed';
            $error = $e->getMessage();

            Log::warning('[ai-page-builder] watcher-triggered run failed', [
                'watcher' => $watcher->id,
                'target_type' => $watcher->target_type,
                'target_key' => $watcher->target_key,
                'error' => $error,
            ]);
        }

        $watcher->forceFill([
            'last_fired_at' => Carbon::now(),
            'last_status' => $status,
            'last_error' => $error,
        ])->saveQuietly();
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function runFlow(Watcher $watcher, array $input): void
    {
        /** @var class-string<Flow> $model */
        $model = config('ai-page-builder.models.flow', Flow::class);

        /** @var Flow|null $flow */
        $flow = $model::query()->where('slug', $watcher->target_key)->first();

        if ($flow === null) {
            throw new \RuntimeException(sprintf('Flow "%s" not found.', $watcher->target_key));
        }

        // Tag the recorded run with its watcher so the watcher's Runs tab can
        // find it (meta is provenance only — it never reaches the flow input).
        $this->flows->run($flow, $input, [], ['watcher_id' => $watcher->id]);
    }

    /**
     * Run a watcher's target ONCE with a representative payload, bypassing its
     * conditions — a wiring test from the admin. Stamps telemetry like a real
     * fire. Depth-guarded like the dispatch paths.
     */
    public function testFire(Watcher $watcher): void
    {
        if (self::$depth >= self::MAX_DEPTH) {
            $this->warnDepth(['test' => $watcher->id]);

            return;
        }

        $input = $watcher->source_type === 'state'
            ? ['event' => 'changed', 'key' => (string) $watcher->source_key, 'old' => null, 'new' => 'test']
            : ['event' => (string) ($watcher->event ?? 'created'), 'collection' => (string) $watcher->source_key, 'record' => [], 'old' => []];

        self::$depth++;

        try {
            $this->runTarget($watcher, $input);
        } finally {
            self::$depth--;
        }
    }

    /**
     * Drive the target Function through the public FlowRunner via a synthetic
     * one-node `function` graph (mirrors {@see ScheduleRunner}).
     *
     * @param  array<string,mixed>  $input
     */
    private function runFunction(Watcher $watcher, array $input): void
    {
        /** @var class-string<FlowFunction> $model */
        $model = config('ai-page-builder.models.flow_function', FlowFunction::class);

        if ($model::query()->where('slug', $watcher->target_key)->doesntExist()) {
            throw new \RuntimeException(sprintf('Function "%s" not found.', $watcher->target_key));
        }

        $this->runner->run([
            'start' => 'call',
            'nodes' => [
                'call' => [
                    'type' => 'function',
                    'config' => [
                        'function' => $watcher->target_key,
                        'args' => $input,
                        'output' => 'result',
                    ],
                ],
            ],
        ], $input);
    }

    /**
     * @return class-string<Watcher>
     */
    private function watcherModel(): string
    {
        /** @var class-string<Watcher> */
        return config('ai-page-builder.models.watcher', Watcher::class);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function warnDepth(array $context): void
    {
        Log::warning('[ai-page-builder] watcher dispatch depth limit reached; skipping', $context + [
            'depth' => self::$depth,
        ]);
    }
}
