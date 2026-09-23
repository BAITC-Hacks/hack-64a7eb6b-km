<?php

use App\Http\Controllers\AgentRunController;
use App\Http\Controllers\ToolApprovalController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [AgentRunController::class, 'dashboard'])->name('dashboard');
    Route::get('runs', [AgentRunController::class, 'index'])->name('runs.index');
    Route::post('runs', [AgentRunController::class, 'store'])->middleware('throttle:10,1')->name('runs.store');
    Route::get('runs/{run}', [AgentRunController::class, 'show'])->name('runs.show');
    Route::post('runs/{run}/cancel', [AgentRunController::class, 'cancel'])->name('runs.cancel');
    Route::post('approvals/{approval}', [ToolApprovalController::class, 'update'])->name('approvals.update');
});

require __DIR__.'/settings.php';
