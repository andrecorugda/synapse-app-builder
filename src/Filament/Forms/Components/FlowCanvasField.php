<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * A Drawflow-backed visual flow editor bound to Filament form state.
 *
 * State is an array matching the engine's definition format:
 * { start, nodes: { id: { type, config, next|next_true|next_false } }, _canvas }.
 * `_canvas` carries the raw Drawflow export for loss-less round-trip restore.
 *
 * The Drawflow library + Alpine component are injected into the panel layout by
 * the service provider's render hook (flow-assets.blade.php); this field only
 * mounts the x-data component and wraps the canvas container.
 */
class FlowCanvasField extends Field
{
    protected string $view = 'ai-page-builder::filament.flow-canvas';

    /**
     * Default to an empty definition so the form always has a value.
     *
     * @return array<string, mixed>
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);
    }
}
