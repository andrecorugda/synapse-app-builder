<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Http\Controllers\RecordApiController;
use Illuminate\Support\Facades\Route;

// Auto CRUD for every user-defined model, resolved by its collection key.
Route::get('/{model}', [RecordApiController::class, 'index'])->name('ai-page-builder.records.index');
Route::post('/{model}', [RecordApiController::class, 'store'])->name('ai-page-builder.records.store');
// Schema endpoint — declared before /{model}/{id} so "schema" isn't captured
// as a record id. Returns { fields, display_field, relations } for type-driven
// rendering without any magic field-name guessing.
Route::get('/{model}/schema', [RecordApiController::class, 'schema'])->name('ai-page-builder.records.schema');
// Aggregation for charts/KPIs — declared before /{model}/{id} so "aggregate"
// isn't captured as a record id.
Route::get('/{model}/aggregate', [RecordApiController::class, 'aggregate'])->name('ai-page-builder.records.aggregate');
Route::get('/{model}/{id}', [RecordApiController::class, 'show'])->name('ai-page-builder.records.show');
Route::match(['put', 'patch'], '/{model}/{id}', [RecordApiController::class, 'update'])->name('ai-page-builder.records.update');
Route::delete('/{model}/{id}', [RecordApiController::class, 'destroy'])->name('ai-page-builder.records.destroy');
