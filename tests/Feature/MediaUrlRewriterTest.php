<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Models\Partial;
use Andre\AiPageBuilder\Services\MediaUrlRewriter;

it('rewrites media urls across page columns and project_data leaves', function (): void {
    $page = Page::factory()->create([
        'html' => '<img src="/storage/page-builder/a.png"><img src="/storage/page-builder/b.png">',
        'css' => '.hero{background:url(/storage/page-builder/a.png)}',
        'custom_css' => '.x{background:url(/storage/page-builder/a.png)}',
        'custom_js' => 'const img = "/storage/page-builder/a.png";',
        'project_data' => [
            'assets' => [['src' => '/storage/page-builder/a.png']],
            'pages' => [['html' => 'deep /storage/page-builder/a.png string']],
        ],
    ]);

    $report = app(MediaUrlRewriter::class)->rewrite([
        '/storage/page-builder/a.png' => 'https://cdn.example.com/page-builder/a.png',
    ]);

    $page->refresh();

    expect($page->html)
        ->toContain('https://cdn.example.com/page-builder/a.png')
        ->toContain('/storage/page-builder/b.png') // unmapped URL untouched
        ->and($page->css)->toContain('https://cdn.example.com/page-builder/a.png')
        ->and($page->custom_css)->toContain('https://cdn.example.com/')
        ->and($page->custom_js)->toContain('https://cdn.example.com/')
        ->and($page->project_data['assets'][0]['src'])->toBe('https://cdn.example.com/page-builder/a.png')
        ->and($page->project_data['pages'][0]['html'])->toContain('https://cdn.example.com/')
        ->and(array_sum($report))->toBe(6);
});

it('rewrites partials and email-template pages too', function (): void {
    $email = Page::factory()->create([
        'kind' => 'email',
        'html' => '<img src="/storage/page-builder/logo.png">',
    ]);
    $partial = Partial::create([
        'name' => 'Header',
        'slug' => 'header',
        'html' => '<img src="/storage/page-builder/logo.png">',
        'project_data' => ['assets' => [['src' => '/storage/page-builder/logo.png']]],
    ]);

    app(MediaUrlRewriter::class)->rewrite([
        '/storage/page-builder/logo.png' => 'https://cdn.example.com/logo.png',
    ]);

    expect($email->refresh()->html)->toContain('https://cdn.example.com/logo.png')
        ->and($partial->refresh()->html)->toContain('https://cdn.example.com/logo.png')
        ->and($partial->project_data['assets'][0]['src'])->toBe('https://cdn.example.com/logo.png');
});

it('replaces longer urls first so prefixes cannot clobber them', function (): void {
    $page = Page::factory()->create([
        'html' => '<img src="/m/a.png"><img src="/m/a.png.webp">',
    ]);

    app(MediaUrlRewriter::class)->rewrite([
        '/m/a.png' => 'https://cdn.example.com/a.png',
        '/m/a.png.webp' => 'https://cdn.example.com/a.png.webp',
    ]);

    expect($page->refresh()->html)
        ->toContain('src="https://cdn.example.com/a.png"')
        ->toContain('src="https://cdn.example.com/a.png.webp"');
});

it('counts but persists nothing on a dry run', function (): void {
    $page = Page::factory()->create([
        'html' => '<img src="/storage/page-builder/a.png">',
        'project_data' => ['assets' => [['src' => '/storage/page-builder/a.png']]],
    ]);

    $report = app(MediaUrlRewriter::class)->rewrite(
        ['/storage/page-builder/a.png' => 'https://cdn.example.com/a.png'],
        dryRun: true,
    );

    expect(array_sum($report))->toBe(2)
        ->and($page->refresh()->html)->toContain('/storage/page-builder/a.png')
        ->and($page->project_data['assets'][0]['src'])->toBe('/storage/page-builder/a.png');
});

it('ignores empty and identity mappings', function (): void {
    $page = Page::factory()->create(['html' => '<img src="/m/a.png">']);

    $report = app(MediaUrlRewriter::class)->rewrite([
        '' => 'https://cdn.example.com/x.png',
        '/m/a.png' => '/m/a.png',
    ]);

    expect($report)->toBe([])
        ->and($page->refresh()->html)->toContain('/m/a.png');
});
