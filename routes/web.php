<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Api\AuthController;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/admin', [DashboardController::class, 'index']);
});

Route::get('/basic_email', [AuthController::class, 'basic_email']);
Route::get('/verifyAccount/{token}', [AuthController::class, 'verifyAccount'])->name('user.verify');
