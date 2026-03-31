<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class RoleLogin extends Component
{
    public string $role = 'student';
    public string $roleName = '';
    
    public string $email = '';
    public string $password = '';
    public bool $remember = false;

    public function mount(string $role, string $roleName)
    {
        $this->role = $role;
        $this->roleName = $roleName;
    }

    public function login()
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $guard = $this->role === 'parent' ? 'guardian' : $this->role;

        if (Auth::guard($guard)->attempt([
            'email' => $this->email,
            'password' => $this->password,
        ], $this->remember)) {
            
            session()->regenerate();
            
            return redirect()->intended(route($this->role . '.dashboard'));
        }

        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }

    public function render()
    {
        return view('livewire.auth.role-login')
            ->layout('layouts.auth', ['title' => 'تسجيل الدخول - ' . $this->roleName]);
    }
}
