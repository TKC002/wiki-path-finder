<?php

use App\Http\Controllers\PathFinderController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PathFinderController::class, 'index'])->name('finder.index');
Route::post('/find-path', [PathFinderController::class, 'findPath'])->name('finder.find');