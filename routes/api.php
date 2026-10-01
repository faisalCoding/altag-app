<?php

use App\Http\Controllers\Api\Teacher\AttendanceController;
use App\Http\Controllers\Api\Teacher\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/teacher/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/teacher/attendance', [AttendanceController::class, 'index']);
    Route::post('/teacher/attendance', [AttendanceController::class, 'store']);
});
