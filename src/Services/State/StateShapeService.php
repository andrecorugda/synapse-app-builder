<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services\State;

use Andre\AiPageBuilder\Models\Variable;
use Illuminate\Support\Collection;

/**
 * Turns Object states' nested `shape` schema into flat dotted paths
 * (address, address.city, …) so the page-editor traits and the flow
 * state-picker can offer a value picker instead of a free-typed expression.
 *
 * Kept out of the Blade views (which only inject the result) so both the
 * editor and any future consumer share one implementation.
 */
class StateShapeService
{
    /** Guard against a runaway self/mutually-referencing shape. */
    private const MAX_DEPTH = 6;

    /**
     * All states as [{ key, type, paths: string[] }] for injection into the
     * editors. `paths` is empty for scalar states and lists every bindable
     * dotted path (relative to the state root) for Object states.
     *
     * @return array<int,array{key:string,type:string,paths:array<int,string>}>
     */
    public function catalog(): array
    {
        /** @var class-string<Variable> $model */
        $model = config('ai-page-builder.models.variable', Variable::class);

        /** @var Collection<string,Variable> $byKey */
        $byKey = $model::query()->orderBy('key')->get()->keyBy(fn (Variable $v): string => (string) $v->getAttribute('key'));

        return $byKey
            ->map(fn (Variable $v): array => [
                'key' => (string) $v->getAttribute('key'),
                'type' => (string) $v->getAttribute('type'),
                'paths' => $this->pathsFor($v, $byKey),
            ])
            ->values()
            ->all();
    }

    /**
     * Flattened dotted paths for a single state, resolving nested Objects and
     * reused state refs.
     *
     * @param  Collection<string,Variable>  $byKey
     * @return array<int,string>
     */
    public function pathsFor(Variable $state, Collection $byKey): array
    {
        $shape = $state->getAttribute('shape');

        if (! is_array($shape) || $shape === []) {
            return [];
        }

        return $this->flatten($shape, '', $byKey, [(string) $state->getAttribute('key')], 0);
    }

    /**
     * @param  array<int,mixed>  $fields
     * @param  Collection<string,Variable>  $byKey
     * @param  array<int,string>  $visitedRefs  State keys already expanded on this branch (cycle guard)
     * @return array<int,string>
     */
    private function flatten(array $fields, string $prefix, Collection $byKey, array $visitedRefs, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $paths = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $name = is_string($field['name'] ?? null) ? trim($field['name']) : '';
            if ($name === '') {
                continue;
            }

            $type = is_string($field['type'] ?? null) ? $field['type'] : 'string';
            $path = $prefix === '' ? $name : $prefix.'.'.$name;
            $paths[] = $path;

            if ($type === 'object' && is_array($field['fields'] ?? null)) {
                $paths = array_merge($paths, $this->flatten($field['fields'], $path, $byKey, $visitedRefs, $depth + 1));
            }

            if ($type === 'state') {
                $ref = is_string($field['ref'] ?? null) ? $field['ref'] : '';
                if ($ref === '' || in_array($ref, $visitedRefs, true) || ! $byKey->has($ref)) {
                    continue; // empty, cyclic, or dangling ref → bind the field itself only
                }

                /** @var Variable $refState */
                $refState = $byKey->get($ref);
                $refShape = $refState->getAttribute('shape');
                if (is_array($refShape) && $refShape !== []) {
                    $paths = array_merge(
                        $paths,
                        $this->flatten($refShape, $path, $byKey, [...$visitedRefs, $ref], $depth + 1)
                    );
                }
            }
        }

        return $paths;
    }
}
