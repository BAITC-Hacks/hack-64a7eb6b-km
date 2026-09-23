<?php

use App\Http\Controllers\GisFeatureController;
use App\Http\Controllers\GisLayerController;
use App\Http\Controllers\GisMapStateController;
use Illuminate\Support\Facades\Route;

Route::prefix('gis')->name('gis.')->group(function (): void {
    Route::get('layers', [GisLayerController::class, 'index'])->name('layers.index');
    Route::get('layers/{layer}/metadata', [GisLayerController::class, 'metadata'])->name('metadata');
    Route::get('layers/{layer}/versions', [GisLayerController::class, 'versions'])->name('versions');
    Route::get('layers/{layer}/features', [GisFeatureController::class, 'index'])->name('features.index');
    Route::get('layers/{layer}/export', [GisFeatureController::class, 'export'])->name('export');
    Route::get('layers/{layer}/difference/{left}/{right}/{z}/{x}/{y}.pbf', [GisLayerController::class, 'differenceTile'])->whereNumber(['left', 'right', 'z', 'x', 'y'])->name('difference');
    Route::get('layers/{layer}/compare', [GisFeatureController::class, 'compare'])->name('compare');
    Route::get('layers/{layer}/features/{feature}', [GisFeatureController::class, 'show'])->name('features.show');
    Route::get('layers/{layer}/features/{feature}/history', [GisFeatureController::class, 'history'])->name('features.history');
    Route::get('layers/{layer}/versions/{version}/tiles/{z}/{x}/{y}.{format}', [GisLayerController::class, 'tile'])->whereNumber(['version', 'z', 'x', 'y'])->whereIn('format', ['pbf', 'png'])->name('tiles');
    Route::get('layers/{layer}/versions/{version}/style', [GisLayerController::class, 'style'])->whereNumber('version')->name('style');
    Route::get('layers/{layer}/versions/{version}/resources/{name}', [GisLayerController::class, 'resource'])->whereNumber('version')->where('name', '.*')->name('resource');
    Route::middleware(['auth', 'verified', 'throttle:60,1'])->group(function (): void {
        Route::post('layers', [GisLayerController::class, 'store'])->name('layers.store');
        Route::post('layers/{layer}/features', [GisFeatureController::class, 'store'])->name('features.store');
        Route::put('layers/{layer}/features/{feature}', [GisFeatureController::class, 'update'])->name('features.update');
        Route::delete('layers/{layer}/features/{feature}', [GisFeatureController::class, 'destroy'])->name('features.destroy');
        Route::post('layers/{layer}/features/{feature}/restore', [GisFeatureController::class, 'restore'])->name('features.restore');
        Route::get('state', [GisMapStateController::class, 'show'])->name('state.show');
        Route::put('state', [GisMapStateController::class, 'update'])->name('state.update');
        Route::post('sync', [GisLayerController::class, 'sync'])->middleware('throttle:2,1')->name('sync');
    });
});
