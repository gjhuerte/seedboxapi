<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::put('/mam-id', [DashboardController::class, 'update'])->name('mam-id.update');
Route::delete('/mam-id', [DashboardController::class, 'destroy'])->name('mam-id.destroy');
Route::put('/interval', [DashboardController::class, 'interval'])->name('interval.update');
Route::delete('/interval', [DashboardController::class, 'resetInterval'])->name('interval.reset');
Route::post('/sync', [DashboardController::class, 'sync'])->middleware('throttle:6,1')->name('sync');
