<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Controllers\RecordApiController;
use Illuminate\Support\Facades\Route;

// Auto CRUD for every user-defined model, resolved by its collection key.
Route::get('/{model}', [RecordApiController::class, 'index'])->name('ai-page-builder.records.index');
Route::post('/{model}', [RecordApiController::class, 'store'])->name('ai-page-builder.records.store');
Route::get('/{model}/{id}', [RecordApiController::class, 'show'])->name('ai-page-builder.records.show');
Route::match(['put', 'patch'], '/{model}/{id}', [RecordApiController::class, 'update'])->name('ai-page-builder.records.update');
Route::delete('/{model}/{id}', [RecordApiController::class, 'destroy'])->name('ai-page-builder.records.destroy');
