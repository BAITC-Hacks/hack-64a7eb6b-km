<?php

use App\Http\Controllers\AgentRunController;
use App\Http\Controllers\ScenarioAiController;
use App\Http\Controllers\SimulationScenarioController;
use App\Http\Controllers\ToolApprovalController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [SimulationScenarioController::class, 'dashboard'])->name('dashboard');
    Route::get('scenarios', [SimulationScenarioController::class, 'index'])->name('scenarios.index');
    Route::get('scenarios/create', [SimulationScenarioController::class, 'create'])->name('scenarios.create');
    Route::get('scenarios/compare', [SimulationScenarioController::class, 'compare'])->name('scenarios.compare');
    Route::post('scenarios/preview', [SimulationScenarioController::class, 'preview'])->middleware('throttle:60,1')->name('scenarios.preview');
    Route::post('scenarios', [SimulationScenarioController::class, 'store'])->middleware('throttle:20,1')->name('scenarios.store');
    Route::get('scenarios/{scenario}', [SimulationScenarioController::class, 'show'])->name('scenarios.show');
    Route::post('scenarios/{scenario}/analysis', [ScenarioAiController::class, 'analysis'])->middleware('throttle:10,1')->name('scenarios.analysis');
    Route::post('scenarios/{scenario}/messages', [ScenarioAiController::class, 'message'])->middleware('throttle:10,1')->name('scenarios.messages');
    Route::get('runs', [AgentRunController::class, 'index'])->name('runs.index');
    Route::post('runs', [AgentRunController::class, 'store'])->middleware('throttle:10,1')->name('runs.store');
    Route::get('runs/{run}', [AgentRunController::class, 'show'])->name('runs.show');
    Route::post('runs/{run}/cancel', [AgentRunController::class, 'cancel'])->name('runs.cancel');
    Route::post('approvals/{approval}', [ToolApprovalController::class, 'update'])->name('approvals.update');
});

require __DIR__.'/settings.php';
