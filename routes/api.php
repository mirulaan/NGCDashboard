<?php

use App\Http\Controllers\DashboardController;
use App\http\Controllers\testController;
use Illuminate\Support\Facades\Route;

// The 3rd party app will POST to: your-domain.com/api/system-status
Route::post('/system-status', [DashboardController::class, 'store']);

Route::post('/system-status/test', [TestController::class, 'test']);

// SFTP API endpoint for dropdown fetching
    Route::get('/api/sftp/files', [AuthController::class, 'getSftpFiles'])->name('api.sftp.files');