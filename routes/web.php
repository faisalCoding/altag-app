<?php

use App\Livewire\Auth\Manager\Login;
use App\Livewire\Auth\Manager\Register;
use App\Livewire\Manager\PendingApprovals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/pending-approval', fn () => view('pending-approval'))
    ->middleware('auth:manager,supervisor,teacher,student,guardian')
    ->name('pending-approval');

Route::post('logout', function (Request $request) {
    $guards = ['manager', 'supervisor', 'teacher', 'student', 'guardian', 'web'];
    foreach ($guards as $guard) {
        if (auth()->guard($guard)->check()) {
            auth()->guard($guard)->logout();
        }
    }

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('home');
})->name('logout');

$roles = [
    'manager' => 'مدير',
    'supervisor' => 'مشرف',
    'teacher' => 'معلم',
    'student' => 'طالب',
    'guardian' => 'ولي أمر',
];

Route::middleware('auth:manager,supervisor,teacher,student,guardian')->group(function () use ($roles) {
    Route::get('dashboard', function () use ($roles) {
        foreach (array_keys($roles) as $roleKey) {
            if (auth()->guard($roleKey)->check()) {
                return redirect()->route("{$roleKey}.dashboard");
            }
        }

        return redirect()->route('home');
    })->name('dashboard');
});

Route::middleware(['auth:manager', 'approved'])->prefix('manager')->name('manager.')->group(function () {
    Route::livewire('/pending-approvals', PendingApprovals::class)->name('pending-approvals');
    Route::view('/stages', 'manager.stages')->name('stages');
    Route::view('/circles', 'manager.circles')->name('circles');
    Route::view('/supervisors', 'manager.supervisors')->name('supervisors');
    Route::view('/teachers', 'manager.teachers')->name('teachers');
    Route::view('/students', 'manager.students')->name('students');
    Route::view('/guardians', 'manager.guardians')->name('guardians');
    Route::view('/attendance-reports', 'manager.attendance-reports')->name('attendance-reports');
    Route::view('/ai-analysis', 'manager.ai-analysis')->name('ai-analysis');
});

// القاسم المشترك لمسارات الضيوف (Guest Routes) لكل دور
Route::middleware('guest:manager')->prefix('manager')->name('manager.')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
});

Route::middleware('guest:supervisor')->prefix('supervisor')->name('supervisor.')->group(function () {
    Route::get('/login', App\Livewire\Auth\Supervisor\Login::class)->name('login');
    Route::get('/register', App\Livewire\Auth\Supervisor\Register::class)->name('register');
});

Route::middleware('guest:teacher')->prefix('teacher')->name('teacher.')->group(function () {
    Route::get('/login', App\Livewire\Auth\Teacher\Login::class)->name('login');
    Route::get('/register', App\Livewire\Auth\Teacher\Register::class)->name('register');
});

Route::middleware('guest:student')->prefix('student')->name('student.')->group(function () {
    Route::get('/login', App\Livewire\Auth\Student\Login::class)->name('login');
    Route::get('/register', App\Livewire\Auth\Student\Register::class)->name('register');
});

Route::middleware('guest:guardian')->prefix('parent')->name('parent.')->group(function () {
    Route::get('/login', App\Livewire\Auth\Guardian\Login::class)->name('login');
    Route::get('/register', App\Livewire\Auth\Guardian\Register::class)->name('register');
});

// مسارات لوحة التحكم (Dashboard Routes) لكل دور
Route::middleware(['auth:manager', 'approved'])->get('/manager/dashboard', fn () => view('manager.dashboard'))->name('manager.dashboard');
Route::middleware(['auth:supervisor', 'approved'])->get('/supervisor/dashboard', fn () => view('supervisor.dashboard'))->name('supervisor.dashboard');
Route::middleware(['auth:teacher', 'approved'])->prefix('teacher')->name('teacher.')->group(function () {
    Route::get('/dashboard', fn () => view('teacher.dashboard'))->name('dashboard');
    Route::view('/attendance', 'teacher.attendance')->name('attendance');
});
Route::middleware(['auth:student', 'approved'])->get('/student/dashboard', fn () => view('student.dashboard'))->name('student.dashboard');
Route::middleware(['auth:guardian', 'approved'])->get('/parent/dashboard', fn () => view('guardian.dashboard'))->name('parent.dashboard');

Route::get('/test', function () {})->name('test');
require __DIR__.'/settings.php';
