<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Services\Settings;

it('lists published indexable pages in the sitemap', function (): void {
    Page::factory()->published()->create([
        'slug' => 'live-page',
        'kind' => 'page',
        'html' => '<h1>live</h1>',
    ]);

    // A draft and a noindex page must NOT appear.
    Page::factory()->create(['slug' => 'draft-page', 'kind' => 'page']); // draft
    Page::factory()->published()->create([
        'slug' => 'hidden-page',
        'kind' => 'page',
        'meta' => ['noindex' => true],
    ]);

    $response = $this->get('/p/sitemap.xml')
        ->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('application/xml');

    $response
        ->assertSee('/p/live-page', false)
        ->assertSee('<lastmod>', false)
        ->assertDontSee('/p/draft-page', false)
        ->assertDontSee('/p/hidden-page', false);
});

it('lists the home page at the prefix root, not at its slug', function (): void {
    Page::factory()->published()->create([
        'slug' => 'welcome',
        'kind' => 'page',
        'html' => '<h1>home</h1>',
    ]);

    app(Settings::class)->set('home_page', 'welcome');

    $body = $this->get('/p/sitemap.xml')->assertOk()->getContent();

    // The home page's <loc> is the prefix root, and its /welcome slug URL is
    // not also emitted.
    expect($body)
        ->toContain('<loc>'.url('/p').'</loc>')
        ->not->toContain('/p/welcome');
});

it('serves robots.txt pointing at the sitemap', function (): void {
    $response = $this->get('/p/robots.txt')->assertOk();

    expect($response->headers->get('Content-Type'))->toContain('text/plain');

    $response
        ->assertSee('User-agent: *', false)
        ->assertSee('Allow: /', false)
        ->assertSee('Sitemap: '.route('ai-page-builder.sitemap'), false);
});

it('disallows noindex pages in robots.txt', function (): void {
    Page::factory()->published()->create([
        'slug' => 'hidden-page',
        'kind' => 'page',
        'meta' => ['noindex' => true],
    ]);

    $this->get('/p/robots.txt')
        ->assertOk()
        ->assertSee('Disallow: /p/hidden-page', false);
});
