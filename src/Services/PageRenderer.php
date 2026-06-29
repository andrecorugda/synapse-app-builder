<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Partial;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
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
        // Expand reusable partials (<div data-pb-partial="slug">) into their
        // current html, collecting their css — so editing a partial reflects
        // on every page that embeds it.
        [$html, $css] = $this->expandPartials((string) $page->html, (string) $page->css);

        return view('ai-page-builder::render.page', [
            'page' => $page,
            'html' => $html,
            'css' => $css,
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

    /**
     * Replace `<el data-pb-partial="slug">…</el>` placeholders with the referenced
     * partial's html (dropping the editor placeholder), and append each used
     * partial's css once.
     *
     * @return array{0:string,1:string}
     */
    private function expandPartials(string $html, string $css): array
    {
        if (! str_contains($html, 'data-pb-partial=')) {
            return [$html, $css];
        }

        /** @var class-string<Model> $model */
        $model = config('ai-page-builder.models.partial', Partial::class);

        $extraCss = [];

        $html = preg_replace_callback(
            '/<([a-zA-Z0-9]+)\b[^>]*\bdata-pb-partial="([A-Za-z0-9\-_]+)"[^>]*>.*?<\/\1>/s',
            function (array $m) use ($model, &$extraCss): string {
                $partial = $model::query()->where('slug', $m[2])->first();
                if ($partial === null) {
                    return '';
                }
                if (! empty($partial->css)) {
                    $extraCss[$m[2]] = (string) $partial->css; // keyed by slug = used once
                }

                return (string) $partial->html;
            },
            $html,
        ) ?? $html;

        if ($extraCss !== []) {
            $css = $css."\n".implode("\n", $extraCss);
        }

        return [$html, $css];
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
