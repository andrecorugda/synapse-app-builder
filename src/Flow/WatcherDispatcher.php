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
                $criteria = (array) (($watcher->config['criteria'] ?? []));

                if ($criteria !== [] && ! $this->matchesCriteria($record, $criteria)) {
                    continue;
                }

                $this->runTarget($watcher, $input);
            }
        } finally {
            self::$depth--;
        }
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
     * A state watcher may narrow to a transition (`from`/`to`) or an operator
     * test on the new value (`op`/`value`). No condition = fire on any change.
     */
    private function stateConditionMet(Watcher $watcher, mixed $old, mixed $new): bool
    {
        $config = (array) ($watcher->config ?? []);

        if (array_key_exists('from', $config) && $old != $config['from']) {
            return false;
        }

        if (array_key_exists('to', $config) && $new != $config['to']) {
            return false;
        }

        if (($config['op'] ?? null) !== null && $config['op'] !== '') {
            return $this->matchesCondition($new, (string) $config['op'], $config['value'] ?? null);
        }

        return true;
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

        $this->flows->run($flow, $input);
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
