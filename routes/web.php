<?php

use App\Livewire\Auth\RoleLogin;
use App\Livewire\Auth\RoleRegister;
use App\Livewire\Manager\PendingApprovals;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/pending-approval', fn () => view('pending-approval'))
    ->middleware('auth:manager,supervisor,teacher,student,guardian')
    ->name('pending-approval');

$roles = [
    'manager' => 'مدير',
    'supervisor' => 'مشرف',
    'teacher' => 'معلم',
    'student' => 'طالب',
    'parent' => 'ولي أمر',
];

Route::middleware('auth:manager,supervisor,teacher,student,guardian')->group(function () use ($roles) {
    Route::get('dashboard', function () use ($roles) {
        $guard = null;
        foreach (array_keys($roles) as $roleKey) {
            $checkGuard = $roleKey === 'parent' ? 'guardian' : $roleKey;
            if (auth()->guard($checkGuard)->check()) {
                return redirect()->route("{$roleKey}.dashboard");
            }
        }
        return redirect()->route('home');
    })->name('dashboard');
});

Route::livewire('/manager/pending-approvals', PendingApprovals::class)
    ->middleware(['auth:manager', 'approved'])
    ->name('manager.pending-approvals');

foreach ($roles as $roleKey => $roleName) {
    $guard = $roleKey === 'parent' ? 'guardian' : $roleKey;

    Route::get("/{$roleKey}/login", RoleLogin::class)
        ->defaults('role', $roleKey)
        ->defaults('roleName', $roleName)
        ->middleware("guest:{$guard}")
        ->name("{$roleKey}.login");

    Route::get("/{$roleKey}/register", RoleRegister::class)
        ->defaults('role', $roleKey)
        ->defaults('roleName', $roleName)
        ->middleware("guest:{$guard}")
        ->name("{$roleKey}.register");

    Route::get("/{$roleKey}/dashboard", function () use ($roleKey, $roleName) {
        return view('dashboard', ['role' => $roleKey, 'roleName' => $roleName]);
    })->middleware(["auth:{$guard}", 'approved'])->name("{$roleKey}.dashboard");
}

require __DIR__.'/settings.php';
