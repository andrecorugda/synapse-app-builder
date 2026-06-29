<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Page;
use Illuminate\Support\Facades\URL;

it('renders a draft page via a valid temporary signed URL', function (): void {
    // Draft by default — the normal render route would 503 this, but a valid
    // signed preview link must show it.
    $page = Page::factory()->create([
        'slug' => 'sneak-peek',
        'html' => '<section class="pb-hero"><h1>draft-preview-marker</h1></section>',
    ]);

    $url = URL::temporarySignedRoute(
        'ai-page-builder.preview',
        now()->addHours(24),
        ['slug' => $page->slug],
    );

    $this->get($url)
        ->assertOk()
        ->assertSee('draft-preview-marker', false);
});

it('rejects an unsigned request to the preview route with 403', function (): void {
    $page = Page::factory()->create(['slug' => 'sneak-peek']);

    $this->get('/p/preview/'.$page->slug)->assertForbidden();
});

it('404s a signed preview link for an unknown slug', function (): void {
    $url = URL::temporarySignedRoute(
        'ai-page-builder.preview',
        now()->addHours(24),
        ['slug' => 'does-not-exist'],
    );

    $this->get($url)->assertNotFound();
});
