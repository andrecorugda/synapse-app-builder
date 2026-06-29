<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Controllers\PreviewController;
use Andre\AiPageBuilder\Http\Controllers\RenderPageController;
use Illuminate\Support\Facades\Route;

// The render-prefix root (e.g. /p) serves the configured home page.
Route::get('/', [RenderPageController::class, 'home'])
    ->name('ai-page-builder.home');

// Preview a page of ANY status via a temporary signed URL. Registered before
// the catch-all `/{slug}` so it isn't swallowed by it.
Route::get('/preview/{slug}', PreviewController::class)
    ->where('slug', '[A-Za-z0-9\-_]+')
    ->middleware('signed')
    ->name('ai-page-builder.preview');

Route::get('/{slug}', RenderPageController::class)
    ->where('slug', '[A-Za-z0-9\-_]+')
    ->name('ai-page-builder.render');
