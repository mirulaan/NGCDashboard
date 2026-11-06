<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;

// 1. Your standard routes
Route::get('/', [DashboardController::class, 'fleet'])->name('fleet');
Route::get('/node/{plant}/{line}/{side}', [DashboardController::class, 'show'])->name('node.show');

// 2. The Catch-All Fallback Route
// Must be placed at the bottom of the file!
Route::fallback(function () {
    return redirect()->route('fleet');
});

// Login Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'authenticate']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Secure Test Page (Only accessible if logged in)
Route::middleware('auth')->group(function () {

    Route::match(['get', 'post'], '/sftp', [AuthController::class, 'sftp'])->name('secure.test');

// Node Config API & Update Route
    Route::get('/api/sftp/config-options/{plant}', [AuthController::class, 'getSftpOptions'])->name('api.sftp.options');
    Route::post('/node/update-config', [AuthController::class, 'updateNodeConfig'])->name('node.update-config');

    Route::get('/settings', [AuthController::class, 'showSettings'])->name('settings');
    Route::post('/settings/password', [AuthController::class, 'updatePassword'])->name('settings.password');
});

Route::get('/api/sftp/backups/{plant}', [\App\Http\Controllers\DashboardController::class, 'getPlantBackups']);
