<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Controllers\RenderPageController;
use Illuminate\Support\Facades\Route;

Route::get('/{slug}', RenderPageController::class)
    ->where('slug', '[A-Za-z0-9\-_]+')
    ->name('ai-page-builder.render');
