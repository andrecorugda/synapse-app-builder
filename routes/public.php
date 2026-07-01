<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Controllers\PublicUploadController;
use Illuminate\Support\Facades\Route;

Route::post('/pb-upload', PublicUploadController::class)
    ->middleware('throttle:30,1')
    ->name('ai-page-builder.public-upload');
