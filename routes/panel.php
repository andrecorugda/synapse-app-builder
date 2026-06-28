<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::post('/media/upload', [MediaController::class, 'upload'])
    ->name('ai-page-builder.media.upload');
