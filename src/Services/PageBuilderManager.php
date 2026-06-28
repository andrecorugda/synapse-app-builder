<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\Page;

/**
 * Programmatic entry point behind the PageBuilder facade. Phase 1 exposes
 * rendering; the AI generation methods are added in phase 2.
 */
class PageBuilderManager
{
    public function __construct(private readonly PageRenderer $renderer) {}

    /** Fully-rendered (cached) HTML for a published page. */
    public function render(Page $page): string
    {
        return $this->renderer->renderCached($page);
    }

    /** Bust the render cache for a slug. */
    public function forget(string $slug): void
    {
        $this->renderer->forget($slug);
    }
}
