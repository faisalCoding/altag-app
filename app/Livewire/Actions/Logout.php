<?php

namespace App\Livewire\Actions;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke()
    {
        // This device only: the remember token is shared by every device.
        Auth::guard('web')->logoutCurrentDevice();

        Session::invalidate();
        Session::regenerateToken();

        return redirect('/');
    }
}
