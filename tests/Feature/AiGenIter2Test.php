<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Ai\BuildPlanValidator;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Partial;

/**
 * Iteration 2 of the AI-generated app work — the three features that make
 * multi-page apps DRY, functional and reactive:
 *   A. `partials` in the build plan (shared chrome).
 *   B. dynamic active-nav marking (window.__pbCurrentSlug + flow-runtime).
 *   C. Interactive-component expansion at render time.
 */

// ── Feature A: partials in the build plan ────────────────────────────────────

it('applies a partials entry from a build plan, sanitizing its html', function (): void {
    $plan = [
        'partials' => [[
            'slug' => 'site-nav',
            'name' => 'Site nav',
            // Carries an executable directive (@click) + a <script> the sanitizer
            // must strip, plus declarative markup that must survive.
            'html' => '<header class="nav"><a data-pb-page="home">Home</a>'
                .'<button @click="boom()">x</button><script>alert(1)</script></header>',
            'custom_css' => '.nav .is-active{color:#6366f1}',
            'custom_js' => 'console.log("nav");',
        ]],
    ];

    $summary = app(BuildPlanApplier::class)->apply($plan);

    expect($summary['errors'])->toBe([])
        ->and($summary['created']['partials'])->toContain('site-nav');

    $partial = Partial::query()->where('slug', 'site-nav')->first();
    expect($partial)->not->toBeNull()
        ->and($partial->name)->toBe('Site nav')
        // Declarative markup + data-pb-page survive sanitization.
        ->and($partial->html)->toContain('data-pb-page="home"')
        // The executable directive + inline script were sanitized out.
        ->and($partial->html)->not->toContain('@click')
        ->and($partial->html)->not->toContain('<script')
        // custom_css / custom_js kept raw.
        ->and($partial->custom_css)->toContain('.is-active')
        ->and($partial->custom_js)->toContain('console.log("nav")');
});

it('accepts a plan containing partials with no validation errors', function (): void {
    $plan = [
        'partials' => [[
            'slug' => 'footer',
            'name' => 'Footer',
            'html' => '<footer data-pb-block="footer">© Us</footer>',
            'custom_css' => '.f{color:#000}',
        ]],
    ];

    expect(app(BuildPlanValidator::class)->validate($plan))->toBe([]);
});

it('rejects a partial with an invalid slug', function (): void {
    $errors = app(BuildPlanValidator::class)->validate([
        'partials' => [['slug' => 'Not A Slug', 'html' => '<div></div>']],
    ]);

    expect($errors)->not->toBe([]);
    expect(collect($errors)->contains(fn (string $e): bool => str_contains($e, 'partials[0]')))->toBeTrue();
});

// ── Feature C: interactive-component expansion ───────────────────────────────

it('expands a partial AND an interactive record_picker shell at render time', function (): void {
    Partial::create([
        'name' => 'Nav',
        'slug' => 'nav',
        'html' => '<header class="nav">BRAND-NAV<a data-pb-page="home">Home</a></header>',
    ]);

    Page::factory()->published()->create([
        'slug' => 'shop',
        'html' => '<div data-pb-partial="nav">EDITOR</div>'
            .'<div data-pb-block="record_picker" data-pb-collection="products" data-pb-target="cart_items"></div>',
    ]);

    $res = $this->get('/p/shop')->assertOk();

    // Partial expanded.
    $res->assertSee('BRAND-NAV', false)
        ->assertDontSee('EDITOR');

    // Interactive shell expanded into the full picker markup: search input +
    // tile hooks, with the author's collection/target carried through.
    $res->assertSee('data-pb-picker-search', false)
        ->assertSee('data-pb-pick', false)
        ->assertSee('pbRecordPicker($el)', false)
        ->assertSee('data-pb-collection="products"', false)
        ->assertSee('data-pb-target="cart_items"', false);
});

it('does not double-expand an already-full interactive block (idempotent)', function (): void {
    // A block that already carries the runtime x-data binding must be left as-is.
    $full = '<div data-pb-block="stepper" data-pb-state="qty" x-data="pbStepper($el)">'
        .'<input class="pb-stepper__input"></div>';

    Page::factory()->published()->create(['slug' => 'stepper-full', 'html' => $full]);

    $body = $this->get('/p/stepper-full')->assertOk()->getContent();

    // Exactly one x-data="pbStepper($el)" — the block was not expanded again.
    expect(substr_count((string) $body, 'pbStepper($el)'))->toBe(1);
});

// ── Feature B: dynamic active nav ────────────────────────────────────────────

it('injects window.__pbCurrentSlug and marks the matching data-pb-page link active', function (): void {
    Page::factory()->published()->create([
        'slug' => 'about',
        'html' => '<nav><a data-pb-page="home">Home</a><a data-pb-page="about">About</a></nav>',
    ]);

    $res = $this->get('/p/about')->assertOk();

    // The current-slug global is injected for this page.
    $res->assertSee('window.__pbCurrentSlug', false)
        ->assertSee('"about"', false)
        // The flow-runtime marks the matching link is-active + aria-current.
        ->assertSee("classList.add('is-active')", false)
        ->assertSee("setAttribute('aria-current', 'page')", false);
});
