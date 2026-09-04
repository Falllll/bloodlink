<?php

use App\Http\Controllers\Health\LivenessController;
use App\Http\Controllers\Health\ReadinessController;
use Illuminate\Support\Facades\Route;

Route::prefix('health')->name('health.')->group(function () {
    Route::get('/live', LivenessController::class)->name('live');

    Route::get('/ready', ReadinessController::class)
        ->middleware('throttle:60,1')
        ->name('ready');
});