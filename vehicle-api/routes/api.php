<?php

use App\Http\Controllers\Auth\OtpAuthController;
use App\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/send-otp', [OtpAuthController::class, 'sendOtp']);
Route::post('/auth/verify-otp', [OtpAuthController::class, 'verifyOtp']);

Route::get('/vehicles/search/{plateNumber}', [VehicleController::class, 'search']);
