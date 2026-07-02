<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Flow\Concerns;

/**
 * Shared criterion matching for record/state payloads. Criteria mirror
 * RecordQuery filters: `{ field: { op: value } }`, where a bare scalar value is
 * treated as an `eq` test. Unknown operators fail closed (no match).
 *
 * Used by watcher dispatch (collection criteria + state from/to conditions) so
 * there is a single implementation of the operator semantics.
 */
trait MatchesRecordCriteria
{
    /**
     * True when the payload satisfies EVERY criterion.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $criteria
     */
    protected function matchesCriteria(array $payload, array $criteria): bool
    {
        foreach ($criteria as $field => $spec) {
            $actual = $payload[$field] ?? null;
            $conditions = is_array($spec) ? $spec : ['eq' => $spec];

            foreach ($conditions as $op => $expected) {
                if (! $this->matchesCondition($actual, (string) $op, $expected)) {
                    return false;
                }
            }
        }

        return true;
    }

    protected function matchesCondition(mixed $actual, string $op, mixed $expected): bool
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
    protected function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        return array_map('trim', explode(',', (string) $value));
    }
}
