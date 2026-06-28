<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow;

use Andre\AiPageBuilder\Models\Flow;
use Illuminate\Support\Facades\Log;

/**
 * Bridges record-level database events to flows. When a record is created,
 * updated, or deleted in a user-defined collection, this finds every active
 * `collection`-triggered Flow listening for that collection + event (and
 * matching its optional criteria) and runs each one synchronously.
 *
 * A flow may itself write records (via the Record node), which would re-fire
 * the observer — so dispatch is bounded by a small re-entrancy depth guard.
 */
class FlowDispatcher
{
    /**
     * How deep cascading collection-event dispatches may nest before the guard
     * trips. Keeps a flow that writes to its own (or another) triggering
     * collection from recursing without bound.
     */
    private const MAX_DEPTH = 3;

    private static int $depth = 0;

    public function __construct(private readonly FlowManager $flows) {}

    /**
     * Run every matching collection-triggered flow for a record event. Never
     * throws — a misbehaving flow must not break the originating DB write.
     *
     * @param  array<string,mixed>  $record
     */
    public function dispatchCollectionEvent(string $collectionKey, string $event, array $record): void
    {
        if (self::$depth >= self::MAX_DEPTH) {
            Log::warning('[ai-page-builder] collection event dispatch depth limit reached; skipping', [
                'collection' => $collectionKey,
                'event' => $event,
                'depth' => self::$depth,
            ]);

            return;
        }

        $flows = $this->matchingFlows($collectionKey, $event);

        if ($flows === []) {
            return;
        }

        $input = [
            'event' => $event,
            'collection' => $collectionKey,
            'record' => $record,
        ];

        self::$depth++;

        try {
            foreach ($flows as $flow) {
                $criteria = (array) (($flow->trigger_config['criteria'] ?? []));

                if ($criteria !== [] && ! $this->matchesCriteria($record, $criteria)) {
                    continue;
                }

                try {
                    $this->flows->run($flow, $input);
                } catch (\Throwable $e) {
                    Log::warning('[ai-page-builder] collection-triggered flow failed', [
                        'flow' => $flow->slug,
                        'collection' => $collectionKey,
                        'event' => $event,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            self::$depth--;
        }
    }

    /**
     * Active `collection`-triggered flows whose config targets this collection
     * and includes this event.
     *
     * @return array<int,Flow>
     */
    private function matchingFlows(string $collectionKey, string $event): array
    {
        /** @var class-string<Flow> $model */
        $model = config('ai-page-builder.models.flow', Flow::class);

        return $model::query()
            ->active()
            ->where('trigger_type', 'collection')
            ->get()
            ->filter(function (Flow $flow) use ($collectionKey, $event): bool {
                $config = (array) ($flow->trigger_config ?? []);

                return ($config['collection'] ?? null) === $collectionKey
                    && in_array($event, (array) ($config['events'] ?? []), true);
            })
            ->values()
            ->all();
    }

    /**
     * True when the record satisfies EVERY criterion. Criteria shape mirrors
     * RecordQuery filters: `{ field: { op: value } }`. A bare scalar value is
     * treated as an `eq` test. Unknown operators fail closed (no match).
     *
     * @param  array<string,mixed>  $record
     * @param  array<string,mixed>  $criteria
     */
    private function matchesCriteria(array $record, array $criteria): bool
    {
        foreach ($criteria as $field => $spec) {
            $actual = $record[$field] ?? null;
            $conditions = is_array($spec) ? $spec : ['eq' => $spec];

            foreach ($conditions as $op => $expected) {
                if (! $this->matchesCondition($actual, (string) $op, $expected)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function matchesCondition(mixed $actual, string $op, mixed $expected): bool
    {
        return match ($op) {
            'eq' => $actual == $expected,
            'neq' => $actual != $expected,
            'gt' => $actual > $expected,
            'gte' => $actual >= $expected,
            'lt' => $actual < $expected,
            'lte' => $actual <= $expected,
            'like' => $expected !== null
                && str_contains((string) $actual, (string) $expected),
            'in' => in_array($actual, $this->toList($expected), false),
            'nin' => ! in_array($actual, $this->toList($expected), false),
            'null' => $actual === null,
            'nnull' => $actual !== null,
            default => false,
        };
    }

    /**
     * @return array<int,mixed>
     */
    private function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        return array_map('trim', explode(',', (string) $value));
    }
}
