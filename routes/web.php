<?php

use App\Http\Controllers\HistoryController;
use App\Http\Controllers\PathFinderController;
use App\Http\Controllers\StatsController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PathFinderController::class, 'index'])->name('finder.index');
Route::get('/find-path/stream', [PathFinderController::class, 'stream'])->name('finder.stream');
Route::get('/suggest', [PathFinderController::class, 'suggest'])->name('finder.suggest');

Route::get('/history',      [HistoryController::class, 'index'])->name('history.index');
Route::get('/history/{id}', [HistoryController::class, 'show'])->name('history.show')->whereNumber('id');

Route::get('/stats',        [StatsController::class, 'index'])->name('stats.index');