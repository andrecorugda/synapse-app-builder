<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\PageRenderer;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RenderPageController
{
    /**
     * Render a published page by slug at `/{prefix}/{slug}`.
     */
    public function __invoke(string $slug, PageRenderer $renderer): SymfonyResponse
    {
        return $this->renderSlug($slug, $renderer);
    }

    /**
     * Render the configured home page at the render-prefix root (and, when
     * `routes.home_at_root` is on, at the site root `/`). The home page is the
     * one whose slug is stored in the `home_page` setting; 404 if none is set
     * or it is not published.
     */
    public function home(PageRenderer $renderer, Settings $settings): SymfonyResponse
    {
        $slug = $settings->get('home_page');

        abort_if(! is_string($slug) || $slug === '', SymfonyResponse::HTTP_NOT_FOUND);

        return $this->renderSlug($slug, $renderer);
    }

    private function renderSlug(string $slug, PageRenderer $renderer): SymfonyResponse
    {
        /** @var class-string<Page> $model */
        $model = config('ai-page-builder.models.page', Page::class);

        $page = $model::query()->published()->where('slug', $slug)->first();

        abort_if($page === null, SymfonyResponse::HTTP_NOT_FOUND);

        // Page-level gate: a page flagged requires_auth is served only to a
        // logged-in end-user; guests are sent to the login page (with intended
        // so they return here after signing in).
        if ($page->requires_auth && config('ai-page-builder.auth.enabled', true)) {
            $guard = (string) config('ai-page-builder.auth.guard', 'pb');
            if (! Auth::guard($guard)->check()) {
                return redirect()->guest('/'.trim((string) config('ai-page-builder.auth.login_path', 'login'), '/'));
            }
        }

        return new Response($renderer->renderCached($page));
    }
}
