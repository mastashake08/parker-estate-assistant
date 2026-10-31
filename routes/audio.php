<?php

use App\Http\Controllers\Audio\AudioController;
use App\Http\Controllers\Audio\AudioGenerationController;
use App\Http\Controllers\Audio\BrowserGenerationController;
use App\Http\Controllers\Audio\MusicModelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('audio', [AudioController::class, 'index'])->name('audio.index');
    Route::post('audio/generations', [AudioGenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('audio.generations.store');
    Route::post('audio/generations/browser', [BrowserGenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('audio.generations.browser.store');
    Route::get('audio/generations/{generation}/file', [AudioGenerationController::class, 'show'])
        ->name('audio.generations.show');
    Route::delete('audio/generations/{generation}', [AudioGenerationController::class, 'destroy'])
        ->name('audio.generations.destroy');
    Route::post('audio/music-models', [MusicModelController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('audio.music-models.store');
});
