<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Controllers\PreviewController;
use Andre\AiPageBuilder\Http\Controllers\RenderPageController;
use Andre\AiPageBuilder\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

// The render-prefix root (e.g. /p) serves the configured home page.
Route::get('/', [RenderPageController::class, 'home'])
    ->name('ai-page-builder.home');

// SEO endpoints. Declared before the catch-all `/{slug}` so the literal
// `sitemap.xml` / `robots.txt` paths aren't captured as a page slug.
Route::get('/sitemap.xml', [SitemapController::class, 'sitemap'])
    ->name('ai-page-builder.sitemap');
Route::get('/robots.txt', [SitemapController::class, 'robots'])
    ->name('ai-page-builder.robots');

// Preview a page of ANY status via a temporary signed URL. Registered before
// the catch-all `/{slug}` so it isn't swallowed by it.
Route::get('/preview/{slug}', PreviewController::class)
    ->where('slug', '[A-Za-z0-9\-_]+')
    ->middleware('signed')
    ->name('ai-page-builder.preview');

Route::get('/{slug}', RenderPageController::class)
    ->where('slug', '[A-Za-z0-9\-_]+')
    ->name('ai-page-builder.render');
