<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Forms\Components;

use Andre\AiPageBuilder\Models\Variable;
use Filament\Forms\Components\Field;

/**
 * A nestable, typed schema builder for an Object State's `shape`.
 *
 * The state is a JSON array of field definitions:
 *   [{ name, type }, …] where type ∈ string|number|boolean|object|state
 *   • object → carries `fields: [ … ]` (recurses)
 *   • state  → carries `ref: '<state key>'` (reuses another state's shape)
 *
 * Binding path pickers (page traits + flow state-picker) flatten this into dotted
 * paths (address, address.to, …) so authors select a value instead of typing it.
 */
class ObjectShapeField extends Field
{
    protected string $view = 'ai-page-builder::filament.forms.object-shape-field';

    /**
     * Other states offered by the "State (reuse)" field type — excludes the record
     * being edited so a state can't reference itself (indirect cycles are guarded
     * at flatten time).
     *
     * @return array<int,array{key:string,label:string}>
     */
    public function getStateOptions(): array
    {
        $currentKey = $this->getRecord()?->getAttribute('key');

        /** @var class-string<Variable> $model */
        $model = config('ai-page-builder.models.variable', Variable::class);

        return $model::query()
            ->orderBy('key')
            ->get()
            ->filter(fn ($v): bool => $currentKey === null || $v->getAttribute('key') !== $currentKey)
            ->map(fn ($v): array => ['key' => (string) $v->getAttribute('key'), 'label' => (string) $v->getAttribute('key')])
            ->values()
            ->all();
    }
}
