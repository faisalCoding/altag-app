<?php

namespace App\Livewire\Auth\Teacher;

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

        if (Auth::guard('teacher')->attempt([
            'email' => $this->email,
            'password' => $this->password,
        ], $this->remember)) {
            session()->regenerate();

            return redirect()->intended(route('teacher.dashboard'));
        }

        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    public function render()
    {
        return view('livewire.auth.teacher.login')
            ->layout('layouts.auth', ['title' => 'تسجيل الدخول - معلم']);
    }
}
