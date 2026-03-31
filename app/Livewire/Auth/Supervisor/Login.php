<?php

namespace App\Livewire\Auth\Supervisor;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (Auth::guard('supervisor')->attempt([
            'email' => $this->email,
            'password' => $this->password,
        ], $this->remember)) {
            session()->regenerate();

            return redirect()->intended(route('supervisor.dashboard'));
        }

        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    public function render()
    {
        return view('livewire.auth.supervisor.login')
            ->layout('layouts.auth', ['title' => 'تسجيل الدخول - مشرف']);
    }
}
