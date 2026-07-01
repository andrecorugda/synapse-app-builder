<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Services;

use Andre\AiPageBuilder\Blocks\BlockVocabulary;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Partial;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use DOMDocument;
use DOMElement;
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
        // current html, collecting their css and custom css/js — so editing a
        // partial reflects on every page that embeds it.
        [$html, $css, $partialsCustomCss, $partialsCustomJs] = $this->expandPartials((string) $page->html, (string) $page->css);

        // Expand any Interactive-component SHELLS (`<div data-pb-block="record_picker"
        // data-pb-collection="…" data-pb-target="…"></div>`) into their full canonical
        // markup, carrying the author's data-pb-* config through — the AI (and hand
        // authors) reference these components by key + config and never hand-write the
        // internals. Runs after expandPartials so shells inside a partial expand too.
        $html = $this->expandInteractive($html);

        // Page custom CSS first, then partials'. Partials' JS runs first and the
        // page's own JS runs last, consistent with it being the final escape hatch.
        $customCss = trim((string) ($page->custom_css ?? '')."\n".$partialsCustomCss);
        $customJs = trim($partialsCustomJs."\n".(string) ($page->custom_js ?? ''));

        return view('ai-page-builder::render.page', [
            'page' => $page,
            'html' => $html,
            'css' => $css,
            'customCss' => $customCss,
            'customJs' => $customJs,
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
     * partial's html (dropping the editor placeholder), append each used partial's
     * css once, and collect each used partial's custom css/js once.
     *
     * @return array{0:string,1:string,2:string,3:string} [$html, $css, $partialsCustomCss, $partialsCustomJs]
     */
    private function expandPartials(string $html, string $css): array
    {
        if (! str_contains($html, 'data-pb-partial=')) {
            return [$html, $css, '', ''];
        }

        /** @var class-string<Model> $model */
        $model = config('ai-page-builder.models.partial', Partial::class);

        $extraCss = [];
        $extraCustomCss = [];
        $extraCustomJs = [];

        $html = preg_replace_callback(
            '/<([a-zA-Z0-9]+)\b[^>]*\bdata-pb-partial="([A-Za-z0-9\-_]+)"[^>]*>.*?<\/\1>/s',
            function (array $m) use ($model, &$extraCss, &$extraCustomCss, &$extraCustomJs): string {
                $partial = $model::query()->where('slug', $m[2])->first();
                if ($partial === null) {
                    return '';
                }
                $partialCss = (string) $partial->getAttribute('css');
                if ($partialCss !== '') {
                    $extraCss[$m[2]] = $partialCss; // keyed by slug = used once
                }
                $partialCustomCss = (string) $partial->getAttribute('custom_css');
                if ($partialCustomCss !== '') {
                    $extraCustomCss[$m[2]] = $partialCustomCss; // keyed by slug = used once
                }
                $partialCustomJs = (string) $partial->getAttribute('custom_js');
                if ($partialCustomJs !== '') {
                    $extraCustomJs[$m[2]] = $partialCustomJs; // keyed by slug = used once
                }

                return (string) $partial->getAttribute('html');
            },
            $html,
        ) ?? $html;

        if ($extraCss !== []) {
            $css = $css."\n".implode("\n", $extraCss);
        }

        return [$html, $css, implode("\n", $extraCustomCss), implode("\n", $extraCustomJs)];
    }

    /**
     * Expand Interactive-component SHELLS into their full canonical markup.
     *
     * The AI (and hand authors) emit an Interactive component as just its
     * wrapper carrying config attributes — e.g.
     *   `<div data-pb-block="record_picker" data-pb-collection="products"
     *         data-pb-target="cart_items"></div>`
     * — because the component's internals (search box, tile grid, Alpine
     * runtime bindings) are too intricate to hand-write reliably. This step
     * replaces each such shell with the block's canonical `template` from
     * BlockVocabulary, transferring the author's `data-pb-*` config attributes
     * onto the template's root so the runtime (pbRecordPicker / pbGrid /
     * pbStepper / pbRepeater / pbContextMenu in page.blade.php) binds correctly.
     *
     * Idempotent: an already-expanded block carries the runtime's `x-data`
     * binding on its root, so it is left untouched (never double-expanded).
     */
    private function expandInteractive(string $html): string
    {
        if (! str_contains($html, 'data-pb-block=')) {
            return $html;
        }

        $templates = $this->interactiveTemplates();
        if ($templates === []) {
            return $html;
        }

        $keyAlternation = implode('|', array_map('preg_quote', array_keys($templates)));

        // Match an Interactive block element and its (typically empty) body.
        // `(?<open>…)` is the full opening tag; `\1` closes the same tag name.
        // `[^<]*` for the body keeps this shell-only — a fully-expanded block
        // has child elements, so it won't match here (and the x-data guard on
        // the opening tag makes double-expansion impossible either way).
        $pattern = '#<(?<tag>[a-zA-Z0-9]+)\b(?<attrs>[^>]*\bdata-pb-block="(?<key>'.$keyAlternation.')"[^>]*)>(?<body>[^<]*)</\k<tag>>#s';

        return preg_replace_callback($pattern, function (array $m) use ($templates): string {
            $attrs = $m['attrs'];

            // Already the full runtime component (carries x-data) → leave as-is.
            if (preg_match('/\bx-data\s*=/i', $attrs)) {
                return $m[0];
            }

            $template = $templates[$m['key']];

            return $this->applyConfigAttrs($template, $this->configAttrs($attrs));
        }, $html) ?? $html;
    }

    /**
     * The Interactive-category block templates, keyed by block key. Sourced from
     * the live registry so registered third-party Interactive blocks expand too;
     * empty when the registry can't be resolved (e.g. rendering outside a booted
     * app — the html is then returned unchanged).
     *
     * @return array<string,string>
     */
    private function interactiveTemplates(): array
    {
        $out = [];
        try {
            foreach (BlockVocabulary::all() as $block) {
                if ($block->category === 'Interactive') {
                    $out[$block->key] = $block->template;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return $out;
    }

    /**
     * Extract the author's `data-pb-*` config attributes from a shell's opening
     * tag (everything except the identifying `data-pb-block`).
     *
     * @return array<string,string>
     */
    private function configAttrs(string $attrs): array
    {
        if (! preg_match_all('/\b(data-pb-[a-z0-9-]+)\s*=\s*"([^"]*)"/i', $attrs, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if ($name === 'data-pb-block') {
                continue;
            }
            $out[$name] = $match[2];
        }

        return $out;
    }

    /**
     * Overlay the transferred `data-pb-*` config attributes onto the template's
     * root element so the injected collection / target / state flow through to
     * the runtime. Uses DOM to set attributes precisely (adds missing ones,
     * overwrites the template's placeholder defaults).
     *
     * @param  array<string,string>  $config
     */
    private function applyConfigAttrs(string $template, array $config): string
    {
        if ($config === []) {
            return $template;
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<?xml encoding="UTF-8"?><div id="__pb_expand_root__">'.$template.'</div>';

        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__pb_expand_root__');
        if (! $root instanceof DOMElement) {
            return $template;
        }

        // The template's root element is the first element child of the wrapper.
        $blockRoot = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $blockRoot = $child;
                break;
            }
        }
        if (! $blockRoot instanceof DOMElement) {
            return $template;
        }

        foreach ($config as $name => $value) {
            $blockRoot->setAttribute($name, $value);
        }

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out) !== '' ? trim($out) : $template;
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
