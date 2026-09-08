<?php

use App\Http\Controllers\Api\V1\RunController;
use App\Http\Controllers\Api\V1\RunResultController;
use App\Http\Controllers\Api\V1\SourceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware(['auth:bfc', 'bfc.ability:consume'])
    ->group(function (): void {
        Route::post('/runs', [RunController::class, 'store'])->name('api.v1.runs.store');
        Route::get('/runs/{run}', [RunController::class, 'show'])->whereUuid('run')->name('api.v1.runs.show');
        Route::get('/runs/{run}/result', RunResultController::class)->whereUuid('run')->name('api.v1.runs.result');
        Route::post('/sources', [SourceController::class, 'store'])->name('api.v1.sources.store');
        Route::get('/sources/{source}', [SourceController::class, 'show'])->whereUuid('source')->name('api.v1.sources.show');
    });
