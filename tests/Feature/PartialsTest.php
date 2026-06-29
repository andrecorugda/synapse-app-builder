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

it('collects an embedded partial custom css and custom js into the page output', function (): void {
    Partial::create([
        'name' => 'Promo banner',
        'slug' => 'promo-banner',
        'html' => '<aside class="promo">PROMO-PARTIAL</aside>',
        'custom_css' => '.promo{background:#0f0}',
        'custom_js' => 'console.log("promo-partial-js");',
    ]);

    Page::factory()->published()->create([
        'slug' => 'with-partial-custom',
        'html' => '<div data-pb-partial="promo-banner">EDITOR</div><main>body</main>',
    ]);

    $this->get('/p/with-partial-custom')
        ->assertOk()
        ->assertSee('PROMO-PARTIAL', false)                  // partial html injected
        ->assertSee('.promo{background:#0f0}', false)        // partial custom css emitted
        ->assertSee('console.log("promo-partial-js");', false); // partial custom js emitted
});

it('keeps both the page own custom css/js and the embedded partial custom css/js', function (): void {
    Partial::create([
        'name' => 'Footer note',
        'slug' => 'footer-note',
        'html' => '<footer class="fn">FOOTER-PARTIAL</footer>',
        'custom_css' => '.fn{color:#abc123}',
        'custom_js' => 'console.log("footer-partial-js");',
    ]);

    Page::factory()->published()->create([
        'slug' => 'page-and-partial-custom',
        'html' => '<div data-pb-partial="footer-note">EDITOR</div><main>body</main>',
        'custom_css' => '.page-own{color:#654321}',
        'custom_js' => 'console.log("page-own-js");',
    ]);

    $this->get('/p/page-and-partial-custom')
        ->assertOk()
        ->assertSee('.page-own{color:#654321}', false)          // page own custom css
        ->assertSee('.fn{color:#abc123}', false)                // partial custom css
        ->assertSee('console.log("page-own-js");', false)       // page own custom js
        ->assertSee('console.log("footer-partial-js");', false); // partial custom js
});

it('renders fine when an embedded partial has empty/null custom css/js', function (): void {
    Partial::create([
        'name' => 'Plain block',
        'slug' => 'plain-block',
        'html' => '<section class="pb">PLAIN-PARTIAL</section>',
        'custom_css' => null,
        'custom_js' => null,
    ]);

    Page::factory()->published()->create([
        'slug' => 'with-plain-partial',
        'html' => '<div data-pb-partial="plain-block">EDITOR</div><p>still-here</p>',
    ]);

    $this->get('/p/with-plain-partial')
        ->assertOk()
        ->assertSee('PLAIN-PARTIAL', false) // partial html still injected
        ->assertSee('still-here', false)    // page html still renders
        ->assertDontSee('EDITOR');
});
