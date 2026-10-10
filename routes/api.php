<?php

use App\Http\Controllers\Api\Teacher\AttendanceController;
use App\Http\Controllers\Api\Teacher\AuthController;
use App\Http\Controllers\Api\V1\Teacher\AttendanceChangeController;
use App\Http\Controllers\Api\V1\Teacher\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\Teacher\ExamChangeController;
use App\Http\Controllers\Api\V1\Teacher\ExtraPointChangeController;
use App\Http\Controllers\Api\V1\Teacher\MyAttendanceController;
use App\Http\Controllers\Api\V1\Teacher\PeerPairController;
use App\Http\Controllers\Api\V1\Teacher\PlanChangeController;
use App\Http\Controllers\Api\V1\Teacher\ScoreChangeController;
use App\Http\Controllers\Api\V1\Teacher\StudentContactController;
use App\Http\Controllers\Api\V1\Teacher\SyncController;
use App\Http\Controllers\Api\V1\Teacher\TasmeehChangeController;
use Illuminate\Support\Facades\Route;

Route::post('/teacher/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/teacher/attendance', [AttendanceController::class, 'index']);
    Route::post('/teacher/attendance', [AttendanceController::class, 'store']);
});

/*
| The offline-first teacher app. The phone keeps a full copy of what it needs,
| pulled from /sync, and queues its edits for the /…/changes endpoints.
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

        Route::middleware(['teacher.api:teacher.grade-items', 'throttle:120,1'])->group(function () {
            Route::post('/scores/changes', [ScoreChangeController::class, 'store'])->name('scores.changes.store');
            Route::post('/extra-points/changes', [ExtraPointChangeController::class, 'store'])->name('extra-points.changes.store');
        });

        Route::middleware(['teacher.api:teacher.tasmeeh', 'throttle:120,1'])->group(function () {
            Route::post('/tasmeeh/changes', [TasmeehChangeController::class, 'store'])->name('tasmeeh.changes.store');
        });

        Route::middleware(['teacher.api:teacher.student-exams', 'throttle:120,1'])->group(function () {
            Route::post('/exams/changes', [ExamChangeController::class, 'store'])->name('exams.changes.store');
        });

        // Quran plans written on the phone with the app's port of the plan engine.
        Route::middleware(['teacher.api:teacher.plan-creator', 'throttle:120,1'])->group(function () {
            Route::post('/plans/changes', [PlanChangeController::class, 'store'])->name('plans.changes.store');
        });

        // Mutual recitation: the day's pairs, swaps and outcomes.
        Route::middleware(['teacher.api:teacher.pairs', 'throttle:120,1'])->prefix('/pairs')->name('pairs.')->group(function () {
            Route::post('/generate', [PeerPairController::class, 'generate'])->name('generate');
            Route::post('/swap', [PeerPairController::class, 'swap'])->name('swap');
            Route::post('/{pair}/result', [PeerPairController::class, 'record'])->whereNumber('pair')->name('result');
        });

        // The student's number and their guardian, from the student card.
        // The teacher's own roll-call record, as the supervisor marked it.
        Route::middleware(['teacher.api:teacher.my-attendance', 'throttle:60,1'])
            ->get('/my-attendance', [MyAttendanceController::class, 'show'])->name('my-attendance');

        Route::middleware(['teacher.api:teacher.students', 'throttle:60,1'])->prefix('/students/{student}')->name('students.')->whereNumber('student')->group(function () {
            Route::post('/phone', [StudentContactController::class, 'phone'])->name('phone');
            Route::post('/guardian/lookup', [StudentContactController::class, 'lookupGuardian'])->name('guardian.lookup');
            Route::post('/guardian', [StudentContactController::class, 'saveGuardian'])->name('guardian');
            Route::post('/guardian/phone', [StudentContactController::class, 'guardianPhone'])->name('guardian.phone');
        });
    });
});
