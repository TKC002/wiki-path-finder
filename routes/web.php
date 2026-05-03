<?php

use App\Http\Controllers\PathFinderController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PathFinderController::class, 'index'])->name('finder.index');
Route::get('/find-path/stream', [PathFinderController::class, 'stream'])->name('finder.stream');
Route::get('/suggest', [PathFinderController::class, 'suggest'])->name('finder.suggest');