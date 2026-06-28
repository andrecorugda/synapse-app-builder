<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\PageRenderer;

it('renders a published page at its slug', function (): void {
    $page = Page::factory()->published()->create([
        'slug' => 'launch',
        'html' => '<section data-pb-block="hero" class="pb-hero"><h1>Launch</h1></section>',
        'css' => '.pb-hero{color:red}',
        'meta' => ['description' => 'Our launch page'],
    ]);

    $this->get('/p/'.$page->slug)
        ->assertOk()
        ->assertSee('data-pb-block="hero"', false)
        ->assertSee('.pb-hero{color:red}', false)
        ->assertSee('Our launch page', false);
});

it('injects per-page custom JS into the rendered output', function (): void {
    Page::factory()->published()->create([
        'slug' => 'scripted',
        'html' => '<section class="pb-hero">Hi</section>',
        'custom_js' => "console.log('pb-custom-js-marker');",
    ]);

    $this->get('/p/scripted')
        ->assertOk()
        ->assertSee('pb-custom-js-marker', false);
});

it('appends per-page custom CSS to the rendered output', function (): void {
    Page::factory()->published()->create([
        'slug' => 'styled',
        'html' => '<section class="pb-hero">Hi</section>',
        'css' => '.pb-hero{padding:1rem}',
        'custom_css' => '.pb-hero{letter-spacing:-0.02em}',
    ]);

    $this->get('/p/styled')
        ->assertOk()
        ->assertSee('.pb-hero{padding:1rem}', false)
        ->assertSee('letter-spacing:-0.02em', false);
});

it('404s for a draft page', function (): void {
    $page = Page::factory()->create(['slug' => 'secret']); // draft by default

    $this->get('/p/'.$page->slug)->assertNotFound();
});

it('busts the render cache when the page changes', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'pricing', 'html' => '<p>cachebust-one</p>']);

    $this->get('/p/pricing')->assertSee('cachebust-one', false);

    $page->update(['html' => '<p>cachebust-two</p>']);

    $this->get('/p/pricing')->assertSee('cachebust-two', false)->assertDontSee('cachebust-one', false);
});

it('caches rendered output between requests', function (): void {
    config()->set('ai-page-builder.cache.ttl', 3600);

    $page = Page::factory()->published()->create(['slug' => 'cached', 'html' => '<p>cached body</p>']);

    // Prime the cache, then mutate the column directly (bypassing model events)
    // to prove the second read came from cache.
    app(PageRenderer::class)->renderCached($page);
    Page::query()->where('id', $page->id)->update(['html' => '<p>changed in db</p>']);

    $this->get('/p/cached')->assertSee('cached body', false)->assertDontSee('changed in db', false);
});
