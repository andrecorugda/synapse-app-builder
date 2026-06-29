<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Partial;

it('expands a partial placeholder into its html + css at render time', function (): void {
    Partial::create([
        'name' => 'Site header',
        'slug' => 'site-header',
        'html' => '<header class="sh">BRAND-HEADER</header>',
        'css' => '.sh{color:#ff0000}',
    ]);

    Page::factory()->published()->create([
        'slug' => 'with-partial',
        'html' => '<div data-pb-partial="site-header">EDITOR-PLACEHOLDER</div><main>body</main>',
    ]);

    $this->get('/p/with-partial')
        ->assertOk()
        ->assertSee('BRAND-HEADER', false)        // partial html injected
        ->assertSee('.sh{color:#ff0000}', false)  // partial css appended
        ->assertDontSee('EDITOR-PLACEHOLDER');     // editor-only label dropped
});

it('drops an unknown partial placeholder', function (): void {
    Page::factory()->published()->create([
        'slug' => 'ghost-partial',
        'html' => '<div data-pb-partial="does-not-exist">x</div><p>kept</p>',
    ]);

    $this->get('/p/ghost-partial')
        ->assertOk()
        ->assertSee('kept', false)
        ->assertDontSee('does-not-exist');
});
