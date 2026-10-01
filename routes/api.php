<?php

use App\Http\Controllers\Api\Teacher\AttendanceController;
use App\Http\Controllers\Api\Teacher\AuthController;
use App\Http\Controllers\Api\V1\Teacher\AttendanceChangeController;
use App\Http\Controllers\Api\V1\Teacher\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\Teacher\SyncController;
use Illuminate\Support\Facades\Route;

Route::post('/teacher/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/teacher/attendance', [AttendanceController::class, 'index']);
    Route::post('/teacher/attendance', [AttendanceController::class, 'store']);
});

/*
| The offline-first teacher app. The phone keeps a full copy of what it needs,
| pulled from /sync, and queues its edits for /attendance/changes.
*/
Route::prefix('v1/teacher')->name('api.v1.teacher.')->group(function () {
    Route::post('/login', [V1AuthController::class, 'login'])
        ->middleware('throttle:login')
        ->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [V1AuthController::class, 'logout'])->name('logout');

        Route::middleware(['teacher.api:teacher.attendance', 'throttle:120,1'])->group(function () {
            Route::get('/sync', SyncController::class)->name('sync');
            Route::post('/attendance/changes', [AttendanceChangeController::class, 'store'])->name('attendance.changes.store');
        });
    });
});
