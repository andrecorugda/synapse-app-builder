<?php

declare(strict_types=1);

namespace Andre\AiPageBuilder\Seeders;

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\Settings;

/**
 * Seeds the built-in system pages — `home` (a starter landing), `not-found`
 * (404) and `maintenance` — as real, editable Synapse pages so every install
 * ships with branded versions a user can tweak in the builder, and points the
 * `home_page` / `not_found_page` / `maintenance_page` settings at them by
 * default (only when unset, so it never overrides a host's own choice).
 *
 * Idempotent: firstOrCreate by slug (never clobbers edits) + set-if-unset for
 * the settings, so it's safe to re-run (boot guard + install command both call
 * it). If a configured page is ever missing, RenderPageController falls back to
 * the bundled `render.system.*` views.
 */
class SystemPagesSeeder
{
    public function __construct(private readonly Settings $settings) {}

    public function run(): void
    {
        /** @var class-string<Page> $model */
        $model = config('ai-page-builder.models.page', Page::class);

        foreach ($this->pages() as $slug => $attrs) {
            $model::query()->firstOrCreate(['slug' => $slug], $attrs);
        }

        // Point the settings at the defaults — but only if the host hasn't
        // already chosen its own, so re-running never clobbers a real choice.
        foreach (['home_page' => 'home', 'not_found_page' => 'not-found', 'maintenance_page' => 'maintenance'] as $key => $slug) {
            if (! $this->settings->has($key)) {
                $this->settings->set($key, $slug);
            }
        }
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function pages(): array
    {
        $base = <<<'CSS'
        :root{--sp-ink:#0a0e1a;--sp-haze:#cdd6ee;--sp-mist:#9aa6c4;--sp-white:#f4f7ff;--sp-indigo:#6366f1;--sp-cyan:#22d3ee}
        .sp-sys{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;text-align:center;color:var(--sp-haze);font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        .sp-sys__card{max-width:34rem}
        .sp-sys__title{font-size:1.6rem;margin:.4rem 0 .6rem;color:var(--sp-white)}
        .sp-sys__text{color:var(--sp-mist);margin:0 0 1.6rem;line-height:1.6}
        .sp-sys__btn{display:inline-block;padding:.75rem 1.4rem;border-radius:.7rem;font-weight:700;text-decoration:none;color:#060912;background:linear-gradient(100deg,var(--sp-indigo),var(--sp-cyan));box-shadow:0 8px 26px -8px rgba(99,102,241,.7)}
        CSS;

        return [
            'home' => [
                'title' => 'Home',
                'kind' => 'page',
                'status' => 'published',
                'requires_auth' => false,
                'html' => '<div class="sp-home"><div class="sp-home__card"><span class="sp-home__eyebrow">Built with Synapse</span><h1 class="sp-home__title">Welcome to your new site</h1><p class="sp-home__text">This is your home page. Open it in the builder to make it your own — or pick a different home page in Settings.</p></div></div>',
                'custom_css' => ':root{--sp-ink:#0a0e1a;--sp-haze:#cdd6ee;--sp-mist:#9aa6c4;--sp-white:#f4f7ff;--sp-indigo:#6366f1;--sp-cyan:#22d3ee}'
                    ."\n.sp-home{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;text-align:center;background:radial-gradient(1000px 560px at 50% -10%,rgba(99,102,241,.2),transparent 60%),var(--sp-ink);color:var(--sp-haze);font-family:ui-sans-serif,system-ui,-apple-system,\"Segoe UI\",Roboto,sans-serif}"
                    ."\n.sp-home__card{max-width:40rem}"
                    ."\n.sp-home__eyebrow{display:inline-block;font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--sp-cyan);margin-bottom:1rem}"
                    ."\n.sp-home__title{font-size:clamp(2rem,6vw,3.2rem);line-height:1.05;margin:0 0 1rem;color:var(--sp-white);background:linear-gradient(100deg,var(--sp-white),var(--sp-haze));-webkit-background-clip:text;background-clip:text}"
                    ."\n.sp-home__text{color:var(--sp-mist);font-size:1.05rem;line-height:1.6;margin:0}",
                'meta' => ['title' => 'Home'],
            ],
            'not-found' => [
                'title' => 'Page not found',
                'kind' => 'page',
                'status' => 'published',
                'requires_auth' => false,
                'html' => '<div class="sp-sys"><div class="sp-sys__card"><p class="sp-sys__code">404</p><h1 class="sp-sys__title">Page not found</h1><p class="sp-sys__text">The page you’re looking for doesn’t exist or may have moved.</p><a class="sp-sys__btn" href="/">Back to home</a></div></div>',
                'custom_css' => $base."\n.sp-sys{background:radial-gradient(900px 500px at 50% -10%,rgba(99,102,241,.18),transparent 60%),var(--sp-ink)}\n.sp-sys__code{margin:0;font-size:clamp(4rem,13vw,7.5rem);font-weight:800;line-height:1;background:linear-gradient(100deg,var(--sp-indigo),var(--sp-cyan));-webkit-background-clip:text;background-clip:text;color:transparent}",
                'meta' => ['title' => '404 — Page not found', 'noindex' => true],
            ],
            'maintenance' => [
                'title' => 'Maintenance',
                'kind' => 'page',
                'status' => 'published',
                'requires_auth' => false,
                'html' => '<div class="sp-sys"><div class="sp-sys__card"><h1 class="sp-sys__title">We’ll be right back</h1><p class="sp-sys__text">The site is down for scheduled maintenance. Please check back shortly.</p></div></div>',
                'custom_css' => $base."\n.sp-sys{background:radial-gradient(900px 500px at 50% -10%,rgba(34,211,238,.16),transparent 60%),var(--sp-ink)}\n.sp-sys__title{font-size:clamp(1.7rem,5vw,2.4rem)}",
                'meta' => ['title' => 'We’ll be right back', 'noindex' => true],
            ],
        ];
    }
}
