<?php

namespace App\Livewire\Auth\Teacher;

use App\Models\Teacher;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Component;

class Register extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function register()
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:teachers'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = Teacher::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
            'is_approved' => false,
        ]);

        event(new Registered($user));

        Auth::guard('teacher')->login($user);

        return redirect()->route('teacher.dashboard');
    }

    public function render()
    {
        return view('livewire.auth.teacher.register')
            ->layout('layouts.auth', ['title' => 'إنشاء حساب - معلم']);
    }
}
