<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\PageRenderer;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RenderPageController
{
    public function __invoke(string $slug, PageRenderer $renderer): Response
    {
        /** @var class-string<Page> $model */
        $model = config('ai-page-builder.models.page', Page::class);

        $page = $model::query()->published()->where('slug', $slug)->first();

        abort_if($page === null, SymfonyResponse::HTTP_NOT_FOUND);

        return new Response($renderer->renderCached($page));
    }
}
