<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\Data\VariableStore;
use Andre\AiPageBuilder\Services\PageRenderer;
use Andre\AiPageBuilder\Services\Settings;

it('boots the reactive Store seeded from State on the published page', function (): void {
    app(VariableStore::class)->set('greeting', 'Hi there', 'string');

    Page::factory()->published()->create([
        'slug' => 'reactive',
        'html' => '<span x-text="$store.app.greeting">placeholder</span>',
    ]);

    $this->get('/p/reactive')
        ->assertOk()
        ->assertSee('alpinejs', false)                 // Alpine loaded
        ->assertSee("Alpine.store('app'", false)        // store seeded on alpine:init
        ->assertSee('Hi there', false);                 // State value injected into window.__pbState
});

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

it('shows the maintenance page (503) for a draft (unpublished) page', function (): void {
    // A page that EXISTS but was unpublished reads as "taken down for
    // maintenance" (503) — distinct from a slug that never existed (404).
    $page = Page::factory()->create(['slug' => 'secret']); // draft by default

    $this->get('/p/'.$page->slug)->assertStatus(503);
});

it('busts the render cache when the page changes', function (): void {
    $page = Page::factory()->published()->create(['slug' => 'pricing', 'html' => '<p>cachebust-one</p>']);

    $this->get('/p/pricing')->assertSee('cachebust-one', false);

    $page->update(['html' => '<p>cachebust-two</p>']);

    $this->get('/p/pricing')->assertSee('cachebust-two', false)->assertDontSee('cachebust-one', false);
});

it('serves the configured home page at the render-prefix root', function (): void {
    Page::factory()->published()->create([
        'slug' => 'welcome',
        'html' => '<h1>home-page-marker</h1>',
    ]);

    app(Settings::class)->set('home_page', 'welcome');

    $this->get('/p')->assertOk()->assertSee('home-page-marker', false);
});

it('404s at the prefix root when no home page is configured', function (): void {
    app(Settings::class)->forget('home_page');

    $this->get('/p')->assertNotFound();
});

it('shows maintenance (503) at the prefix root when the home page is unpublished', function (): void {
    Page::factory()->create(['slug' => 'draft-home', 'html' => '<h1>nope</h1>']); // draft
    app(Settings::class)->set('home_page', 'draft-home');

    $this->get('/p')->assertStatus(503);
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
