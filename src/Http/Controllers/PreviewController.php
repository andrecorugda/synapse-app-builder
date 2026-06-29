<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\PageRenderer;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PreviewController
{
    /**
     * Preview a page of ANY status by slug at `/{prefix}/preview/{slug}`.
     *
     * Unlike the normal render route (published-only, with a maintenance/404
     * gate), this serves the page directly and uncached so drafts are visible
     * and reflect the latest edits. Access is gated entirely by the `signed`
     * middleware on the route: a valid temporary signed link is the share
     * mechanism, and it expires on its own.
     */
    public function __invoke(string $slug, PageRenderer $renderer): SymfonyResponse
    {
        /** @var class-string<Page> $model */
        $model = config('ai-page-builder.models.page', Page::class);

        $page = $model::query()->where('slug', $slug)->first();

        if ($page === null) {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        return new Response($renderer->render($page)->render());
    }
}
