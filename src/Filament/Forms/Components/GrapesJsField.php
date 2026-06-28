<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Filament\Forms\Components;

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Closure;
use Filament\Forms\Components\Field;

/**
 * A GrapesJS editor bound to Filament form state.
 *
 * State is an array shape: { project_data, html, css, selectedComponentId,
 * selectedComponentHtml }. `project_data` (GrapesJS getProjectData()) is the
 * canonical, editable truth; `html`/`css` are the compiled snapshot kept in
 * sync on every change for the render pipeline.
 *
 * The GrapesJS library itself is injected into the panel layout by the service
 * provider's render hook; this field only renders the canvas + Alpine glue.
 */
class GrapesJsField extends Field
{
    protected string $view = 'ai-page-builder::filament.grapesjs-field';

    protected int|Closure $height = 600;

    /** @var array<int,array<string,mixed>>|Closure|null */
    protected array|Closure|null $blocks = null;

    public function height(int|Closure $height): static
    {
        $this->height = $height;

        return $this;
    }

    public function getHeight(): int
    {
        return (int) $this->evaluate($this->height);
    }

    /**
     * @param  array<int,array<string,mixed>>|Closure  $blocks
     */
    public function blocks(array|Closure $blocks): static
    {
        $this->blocks = $blocks;

        return $this;
    }

    /**
     * The block vocabulary serialized for the GrapesJS block manager.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getBlocks(): array
    {
        $resolved = $this->evaluate($this->blocks);

        if (is_array($resolved)) {
            return $resolved;
        }

        return BlockVocabulary::toArray();
    }
}
