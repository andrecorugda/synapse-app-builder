<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Console;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\PageRenderer;
use Andre\AiPageBuilder\Services\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Export the published pages to a self-contained static site (HTML/CSS) that
 * can be hosted for free on GitHub Pages, Cloudflare Pages, Netlify, S3, etc.
 *
 * Each published page becomes `{slug}.html`; internal render links
 * (`/{prefix}/{slug}`) are rewritten to flat `.html` files, and the configured
 * home page is also written as `index.html`. Pages that rely on the live data
 * API / flows / auth won't function statically — this is for content/marketing
 * sites (the published HTML/CSS/Alpine is fully self-contained).
 */
class ExportSiteCommand extends Command
{
    protected $signature = 'ai-page-builder:export
        {--path= : Output directory (default: synapse-export/ in the app root)}
        {--home= : Slug to write as index.html (default: the configured home page)}';

    protected $description = 'Export published pages to a static site (for free static hosting like GitHub Pages).';

    public function handle(PageRenderer $renderer, Settings $settings): int
    {
        /** @var class-string<Page> $model */
        $model = config('ai-page-builder.models.page', Page::class);

        $out = (string) ($this->option('path') ?: base_path('synapse-export'));
        File::ensureDirectoryExists($out);

        $prefix = trim((string) config('ai-page-builder.routes.render_prefix', 'p'), '/');
        $homeSlug = (string) ($this->option('home') ?: $settings->get('home_page') ?: '');

        $pages = $model::query()->where('kind', 'page')->where('status', 'published')->get();
        $count = 0;

        foreach ($pages as $page) {
            $html = $this->staticize($renderer->renderCached($page), $prefix);
            File::put($out.'/'.$page->slug.'.html', $html);
            if ($page->slug === $homeSlug) {
                File::put($out.'/index.html', $html);
            }
            $this->line('  ✓ '.$page->slug.'.html');
            $count++;
        }

        $this->info("Exported {$count} page(s) to {$out}");

        if ($homeSlug === '') {
            $this->warn('No home page set — index.html not written. Pass --home=<slug> or set a home page in Settings.');
        }

        return self::SUCCESS;
    }

    /**
     * Rewrite internal render links (/{prefix}/{slug}, absolute or relative) to
     * flat static files, and the prefix root to index.html.
     */
    private function staticize(string $html, string $prefix): string
    {
        $p = preg_quote($prefix, '#');

        $html = (string) preg_replace(
            '#href="(?:https?://[^/"]+)?/'.$p.'/([a-z0-9\-_]+)"#i',
            'href="$1.html"',
            $html,
        );

        return (string) preg_replace(
            '#href="(?:https?://[^/"]+)?/'.$p.'/?"#i',
            'href="index.html"',
            $html,
        );
    }
}
