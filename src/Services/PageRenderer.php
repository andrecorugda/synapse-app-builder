<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

/**
 * Assembles the front-end HTML for a published page (html + css + SEO meta)
 * and caches it. The cache is busted by the model's saved/deleted events
 * (wired in the service provider / model boot).
 */
class PageRenderer
{
    public function render(Page $page, bool $static = false): View
    {
        return view('ai-page-builder::render.page', [
            'page' => $page,
            'html' => (string) $page->html,
            'css' => (string) $page->css,
            'customCss' => (string) ($page->custom_css ?? ''),
            'customJs' => (string) ($page->custom_js ?? ''),
            // Seed the published page's reactive Store from persistent States.
            'state' => app(VariableStore::class)->all(),
            'meta' => is_array($page->meta) ? $page->meta : [],
            'title' => $page->title,
            // Static export: omit the live backend calls (the auth/me fetch) so
            // the page has zero failing requests when hosted without a backend.
            'static' => $static,
        ]);
    }

    /** Render for static export (no backend runtime calls, no cache). */
    public function renderStatic(Page $page): string
    {
        return $this->render($page, static: true)->render();
    }

    /**
     * Cached, fully-rendered HTML string for a published page slug.
     */
    public function renderCached(Page $page): string
    {
        $ttl = (int) config('ai-page-builder.cache.ttl', 3600);

        if ($ttl <= 0) {
            return $this->render($page)->render();
        }

        return $this->cache()->remember(
            $this->cacheKey($page->slug),
            $ttl,
            fn (): string => $this->render($page)->render(),
        );
    }

    public function forget(string $slug): void
    {
        $this->cache()->forget($this->cacheKey($slug));
    }

    private function cacheKey(string $slug): string
    {
        return ((string) config('ai-page-builder.cache.prefix', 'ai-page-builder:rendered:')).$slug;
    }

    private function cache(): Repository
    {
        $store = config('ai-page-builder.cache.store');

        return $store ? Cache::store($store) : Cache::store();
    }
}
