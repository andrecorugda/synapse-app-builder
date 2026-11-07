<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Controllers\AiChatController;
use Andre\AiPageBuilder\Http\Controllers\CodeLintController;
use Andre\AiPageBuilder\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

// Every route here runs behind the panel middleware, which ALWAYS ends with
// EnsureSynapseAdmin (see AiPageBuilderServiceProvider::panelMiddleware()).

Route::post('/media/upload', [MediaController::class, 'upload'])
    ->name('ai-page-builder.media.upload');

Route::post('/lint/php', [CodeLintController::class, 'php'])
    ->name('ai-page-builder.lint.php');

// Dockable AI chat (admin panel): propose a plan, then apply it. Throttled:
// each send is a paid AI call, and an apply writes pages/flows/functions.
Route::post('/ai-chat', [AiChatController::class, 'send'])
    ->middleware('throttle:30,1')
    ->name('ai-page-builder.ai-chat.send');
Route::post('/ai-chat/apply', [AiChatController::class, 'apply'])
    ->middleware('throttle:30,1')
    ->name('ai-page-builder.ai-chat.apply');
