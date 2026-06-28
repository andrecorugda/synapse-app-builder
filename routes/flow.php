<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Controllers\FlowController;
use Illuminate\Support\Facades\Route;

Route::post('/{slug}', [FlowController::class, 'run'])
    ->where('slug', '[A-Za-z0-9\-_]+')
    ->name('ai-page-builder.flow.run');
