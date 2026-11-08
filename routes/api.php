<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\testController;
use Illuminate\Support\Facades\Route;

// The 3rd party app will POST to: your-domain.com/api/system-status
Route::post('/system-status', [DashboardController::class, 'store']);

Route::post('/system-status/test', [testController::class, 'test']);
