<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Http\Controllers;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SitemapController
{
    /**
     * An XML sitemap of every published, indexable `kind=page` page, plus the
     * configured home page at the render-prefix root.
     */
    public function sitemap(Settings $settings): Response
    {
        $homeSlug = $settings->get('home_page');
        $homeSlug = is_string($homeSlug) && $homeSlug !== '' ? $homeSlug : null;

        $entries = $this->indexablePages()
            ->map(function (Page $page) use ($homeSlug): string {
                // The home page is reachable at the prefix root, so list its
                // canonical URL there rather than at /{slug}.
                $loc = $homeSlug !== null && $page->slug === $homeSlug
                    ? $this->rootUrl()
                    : route('ai-page-builder.render', ['slug' => $page->slug]);

                return '  <url>'
                    .'<loc>'.htmlspecialchars($loc, ENT_XML1).'</loc>'
                    .'<lastmod>'.$this->w3cDate($page->getAttributeValue('updated_at')).'</lastmod>'
                    .'</url>';
            })
            ->implode("\n");

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .($entries !== '' ? $entries."\n" : '')
            .'</urlset>'."\n";

        return new Response($xml, Response::HTTP_OK, [
            'Content-Type' => 'application/xml; charset=UTF-8',
        ]);
    }

    /**
     * A robots.txt that allows everything and points crawlers at the sitemap.
     * Noindex pages are additionally Disallow-ed so a polite crawler skips them.
     */
    public function robots(): Response
    {
        $lines = [
            'User-agent: *',
            'Allow: /',
        ];

        foreach ($this->noindexPages() as $page) {
            $lines[] = 'Disallow: '.$this->path(route('ai-page-builder.render', ['slug' => $page->slug]));
        }

        $lines[] = 'Sitemap: '.route('ai-page-builder.sitemap');

        return new Response(implode("\n", $lines)."\n", Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }

    /**
     * Published `kind=page` pages whose meta.noindex is NOT set.
     *
     * @return Collection<int, Page>
     */
    private function indexablePages(): Collection
    {
        return $this->pageModel()::query()
            ->published()
            ->pages()
            ->orderBy('slug')
            ->get()
            ->reject(fn (Page $page): bool => $this->isNoindex($page))
            ->values();
    }

    /**
     * Published `kind=page` pages flagged meta.noindex.
     *
     * @return Collection<int, Page>
     */
    private function noindexPages(): Collection
    {
        return $this->pageModel()::query()
            ->published()
            ->pages()
            ->orderBy('slug')
            ->get()
            ->filter(fn (Page $page): bool => $this->isNoindex($page))
            ->values();
    }

    private function isNoindex(Page $page): bool
    {
        $meta = $page->meta ?? [];

        return is_array($meta) && filter_var($meta['noindex'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    /** Absolute URL of the render-prefix root (where the home page is served). */
    private function rootUrl(): string
    {
        return url('/'.trim((string) config('ai-page-builder.routes.render_prefix', 'p'), '/'));
    }

    /** The path (and query) portion of an absolute URL, for robots Disallow lines. */
    private function path(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';

        return isset($parsed['query']) ? $path.'?'.$parsed['query'] : $path;
    }

    private function w3cDate(mixed $value): string
    {
        $date = $value instanceof Carbon ? $value : Carbon::now();

        return $date->toAtomString();
    }

    /** @return class-string<Page> */
    private function pageModel(): string
    {
        /** @var class-string<Page> $model */
        $model = config('ai-page-builder.models.page', Page::class);

        return $model;
    }
}
