<?php

use App\Livewire\Settings\Appearance;
use App\Livewire\Settings\Profile;
use App\Livewire\Settings\Security;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:manager,supervisor,teacher,student,guardian,web'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', Profile::class)->name('profile.edit');
});

Route::middleware(['auth:manager,supervisor,teacher,student,guardian,web', 'verified'])->group(function () {
    Route::livewire('settings/appearance', Appearance::class)->name('appearance.edit');

    // NOTE: Fortify's `password.confirm` gate is intentionally omitted here.
    // It redirects to the web-guard-only `user/confirm-password` screen, which
    // locks out every custom guard (manager, supervisor, teacher, student,
    // guardian). Changing the password is already protected by the
    // `current_password` rule, and enabling 2FA still requires TOTP confirmation.
    Route::livewire('settings/security', Security::class)
        ->name('security.edit');
});
